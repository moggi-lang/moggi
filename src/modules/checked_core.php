<?php declare(strict_types=1);

namespace Moggi\Modules;

use Moggi\Semantics\Kinds;
use Moggi\Semantics\TypeExpr\Scheme;
use Moggi\Semantics\TypeExpr\Type;
use Moggi\Semantics\Types\TypeCheckState;
use Moggi\Syntax\Ast;

use function Moggi\Debug\normalizeDisplayPath;
use function Moggi\Semantics\TypeExpr\collectTypeVars;
use function Moggi\Semantics\Types\assertNoPendingConstraintsInExpr;
use function Moggi\Semantics\Types\prune;
use function Moggi\Semantics\Types\typeFail;
use function Moggi\Semantics\Types\typeToString;
use function Moggi\Semantics\Types\walkAstValues;

/**
 * One checked-core record for a declaration the module lowers: an ordinary
 * function, a recursive group's member, an instance method, a class default, a
 * derived member or a foreign import.
 *
 * Captures the checker's settled result: scheme, dictionary evidence,
 * residual decisions and provenance, elaborated term types, and source
 * origins for tooling. Nothing is recomputed from the mutable source tree.
 */
final class CheckedFunction
{
    /**
     * @param string $origin Tooling link for the declaration (`module:file:line:col`).
     * @param list<array{wanted: string, class: string, evidence: string, origin: string}> $evidence
     *   Dictionary parameters in positional ABI order: the wanted obligation
     *   and the given dictionary that discharges it.
     * @param list<array{wanted: string, decision: string, provenance: string, origin: string}> $residuals
     *   Obligations not solved by unification, with their resolution step.
     * @param list<array{origin: string, type: Type}> $termTypes
     *   Term-to-type entries in elaboration order, keyed by source origin
     *   (see {@see termOrigin()}). Tooling: a type is looked up by where a term
     *   is written, not by which term it is.
     * @param array<int, CheckedTerm> $terms
     *   The elaboration's terms, keyed by their own {@see TermId}'s value. Lowering
     *   reads the term at the position it is lowering and refuses when there is
     *   none; it never re-derives payload from the AST node.
     * @param array<string, string> $parameterKinds
     *   Each type parameter's kind in this declaration's signature scope.
     *   Kinds are declaration-local, so identical names may have different
     *   kinds in different declarations.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $origin,
        public readonly Scheme $scheme,
        public readonly array $evidence,
        public readonly array $residuals,
        public readonly array $termTypes = [],
        public readonly array $terms = [],
        public readonly array $parameterKinds = [],
    ) {
    }

    /**
     * This declaration's record with its elaboration attached.
     *
     * The declaration's own facts are settled at the end of the check, but the
     * elaboration it will be lowered from is only final once normalization has
     * rewritten the body ({@see publishElaboration()}), so the two halves are
     * built at different moments and joined here.
     *
     * @param list<array{origin: string, type: Type}> $termTypes
     * @param array<int, CheckedTerm> $terms
     */
    public function withElaboration(array $termTypes, array $terms): self
    {
        return new self(
            $this->name,
            $this->origin,
            $this->scheme,
            $this->evidence,
            $this->residuals,
            $termTypes,
            $terms,
            $this->parameterKinds,
        );
    }

    /**
     * This record with a different evidence vector — the same declaration, the
     * same elaboration, other dictionary parameters.
     *
     * @param list<array{wanted: string, class: string, evidence: string, origin: string}> $evidence
     */
    public function withEvidence(array $evidence): self
    {
        return new self(
            $this->name,
            $this->origin,
            $this->scheme,
            $evidence,
            $this->residuals,
            $this->termTypes,
            $this->terms,
            $this->parameterKinds,
        );
    }

    /**
     * One term of this elaboration, by its own identity.
     *
     * This is how lowering resolves a position it is lowering: the node carries
     * the id the producer wrote, and the record answers with the term that id
     * names. No object id and no origin-string key stands between them, so a
     * copied or reparsed node fails loudly instead of answering by position.
     */
    public function term(int $id): ?CheckedTerm
    {
        return $this->terms[$id] ?? null;
    }

    /**
     * The dictionary parameter names in the order the emitted signature takes
     * them — the positional evidence ABI, stated once for this declaration.
     *
     * @return list<string>
     */
    public function evidenceBinders(): array
    {
        return \array_map(static fn (array $slot): string => $slot['evidence'], $this->evidence);
    }

    /**
     * The origin that names a term inside one elaboration.
     *
     * It is a *link*, not an identity the checker maintains: an outside reader
     * can point at the file and line it names. A synthesized node has no span
     * (line 0) and so no origin; several nodes can share one span, which is why
     * {@see terms} is a list rather than a map from origin to one type.
     */
    public static function termOrigin(Ast\AstNode $node): string
    {
        return \sprintf('%s:%d:%d:%d', $node::class, $node->line, $node->col, $node->endCol);
    }
}

/**
 * The module's checked product: one record per declaration the module lowers.
 *
 * There is no second bucket. A declaration the run lowers either has a record
 * here or the module does not lower at all, so lowering never has to decide
 * whether a declaration it meets is owned — it reads the record or refuses.
 */
final class CheckedCore
{
    /** @param array<string, CheckedFunction> $functions records, by the name the declaration lowers as */
    public function __construct(
        public readonly string $module,
        public readonly array $functions,
    ) {
    }

    public function function(string $name): ?CheckedFunction
    {
        return $this->functions[$name] ?? null;
    }

    /** This product with one declaration's record replaced. */
    public function withFunction(CheckedFunction $function): self
    {
        return new self(
            $this->module,
            [$function->name => $function] + $this->functions,
        );
    }
}

/**
 * Build the module's checked core from the checker's results.
 *
 * One producer for every declaration the module lowers. Each category's check
 * publishes the two facts only it knows — the scheme it settled and the kinds of
 * the parameters that scheme binds (`TypeCheckState::$checkedSchemes`,
 * `$checkedParameterKinds`) — and this function turns those into the record,
 * which is why an instance method, a derived member and a foreign import read
 * the same way an ordinary declaration does.
 */
function checkedCoreFor(TypeCheckState $state, array $items, string $module): CheckedCore
{
    $functions = [];

    foreach ($items as $item) {
        if (! $item instanceof Ast\FunctionDecl || $item->signatureOnly) {
            continue;
        }

        $scheme = $state->checkedSchemes[\spl_object_id($item)] ?? null;
        if (! $scheme instanceof Scheme) {
            throw typeFail(
                $state,
                "the checked core needs the scheme this check settled for `{$module}::{$item->name}`",
                $item,
            );
        }

        assertSignatureClosed($state, $item, $scheme);
        $origin = originFor($state, $item, $module);

        $evidence = evidenceVectorFor($state, $item, $scheme, $origin);

        assertNoPendingConstraintsInExpr($state, $item->body, $item);
        assertTermsCommitted($state, $item, $module);

        $functions[$item->name] = new CheckedFunction(
            $item->name,
            $origin,
            $scheme->withPruned(prune($state, $scheme->type), $scheme->constraints, $scheme->freeTypeVars),
            $evidence,
            residualsFor($state, $item, $origin),
            parameterKinds: parameterKindsFor($state, $item, $scheme, $module),
        );
    }

    return new CheckedCore($module, $functions);
}

/**
 * Refuse a term type the check has already solved but never zonked.
 *
 * A node's internal type is the checker's answer for that term, and the last
 * step of checking prunes the substitution through the body
 * ({@see \Moggi\Semantics\Types\zonkInferredTypesInExpr()}). A published type
 * that still names a solved variable is therefore an answer the check has
 * replaced — exactly the uncommitted meta the boundary promises not to publish —
 * and a consumer has no way to recover the committed one.
 *
 * A variable the substitution does not solve is not refused: a locally
 * generalized binding quantifies its own variable, and that binder is a real
 * part of the term's type rather than a leftover. Those are the only two origins
 * a variable in a settled body has.
 *
 * An evidence plan's `builderHead` needs no such rule: it is the *source*
 * instance head (`evidenceRefOperand()` converts the `TypeNode` the instance
 * declared), so its variables are that instance's own implicitly quantified
 * patterns — `instance Array [s]` — and no inference variable can appear in it.
 */
function assertTermsCommitted(TypeCheckState $state, Ast\FunctionDecl $item, string $module): void
{
    if ($state->subst === []) {
        return;
    }

    $refuse = static function (Ast\AstNode $node) use ($state, $item, $module): void {
        if ($node->inferredInternalType === null) {
            return;
        }

        $solved = [];
        foreach (collectTypeVars($node->inferredInternalType, []) as $name => $_) {
            if (isset($state->subst[$name])) {
                $solved[] = $name;
            }
        }

        if ($solved === []) {
            return;
        }

        throw typeFail(
            $state,
            "the checked boundary refuses `{$module}::{$item->name}`: the term at "
            . CheckedFunction::termOrigin($node) . ' publishes the already solved variable(s) '
            . \implode(', ', $solved) . '; the check replaced them and never zonked the term',
            $item,
        );
    };

    foreach ($item->params as $param) {
        walkAstValues($param, $refuse);
    }
    walkAstValues($item->body, $refuse);
}

/**
 * A declaration that spelled its type out promised a closed one.
 *
 * A variable still uncommitted under a written signature is a producer defect,
 * and refusing it here is the point of the boundary. An *inferred* signature may
 * keep a variable the monomorphism restriction left unsettled — the module end
 * already decided that, and the record states the scheme as it stands, with no
 * parameter kind for a variable the scheme does not bind.
 */
function assertSignatureClosed(TypeCheckState $state, Ast\FunctionDecl $item, Scheme $scheme): void
{
    if ($scheme->freeTypeVars === [] || ! Ast\hasDeclaredSignature($item)) {
        return;
    }

    throw typeFail(
        $state,
        "the checked boundary refuses `{$item->name}`: its type " . typeToString($scheme->type)
        . ' still has the uncommitted variable(s) ' . \implode(', ', \array_keys($scheme->freeTypeVars)),
        $item,
    );
}

/**
 * The site a declaration is written at, as the record publishes it: the unit that
 * owns it, the file it is written in, and the position in that file.
 *
 * The module name does not name the position's file. A unit is written in more
 * than one file once a backend half is merged into it, and the two sides' spans
 * do not agree: a declaration the half supplies keeps the facade's span while the
 * half's code runs, and a declaration the half *is* carries the half's own span.
 * The file is therefore read off the node that holds the position — or, for a node
 * built by desugaring, off the unit being checked, the same fallback
 * `IR\srcLocFromAst` uses. `null` means there is no site to publish.
 */
function sourceSite(string $module, string $file, int $line, int $col): ?string
{
    if ($module === '' || $file === '' || $line <= 0) {
        return null;
    }

    return $module . ':' . normalizeDisplayPath($file) . ':' . $line . ':' . $col;
}

/**
 * The site this declaration is written at, as the record publishes it.
 *
 * A synthesized declaration — an instance method, a class default, a derived
 * member, a foreign axiom — has no span of its own, so the producer that built
 * it records the site it copied its body from (`FunctionDecl::$sourceOrigin`,
 * which can be in another module). Anything without a site is refused here: a
 * record whose origin names no position is a link that opens nothing, and an
 * origin of `module:0:0` is the shape that collision takes.
 */
function originFor(TypeCheckState $state, Ast\FunctionDecl $item, string $module): string
{
    $origin = $item->sourceOrigin
        ?? sourceSite($module, $item->sourceFile ?? $state->filename, $item->line, $item->col);
    if ($origin === null || \str_ends_with($origin, ':0:0')) {
        throw typeFail(
            $state,
            "the checked core cannot record `{$module}::{$item->name}`: the declaration has no"
            . ' source site, so its record would publish an origin no tool can open',
            $item,
        );
    }

    return $origin;
}

/**
 * The dictionary parameters of this declaration, in positional ABI order.
 *
 * One slot per obligation the declaration carries, each pairing the wanted
 * obligation with the given dictionary that discharges it. The vector has to be
 * exactly what the published scheme will pass at a call site — one dictionary
 * per runtime constraint, in the order the scheme took them — because the slot
 * order *is* the ABI the backends emit; a vector that disagrees with the scheme
 * would compile to a signature its callers cannot satisfy.
 *
 * @return list<array{wanted: string, class: string, evidence: string, origin: string}>
 */
function evidenceVectorFor(TypeCheckState $state, Ast\FunctionDecl $item, Scheme $scheme, string $origin): array
{
    $evidence = [];
    $seen = [];
    foreach ($item->constraints as $constraint) {
        if ($constraint->evidence === '') {
            throw typeFail(
                $state,
                "the checked boundary refuses `{$item->name}`: the `{$constraint->class}` obligation has"
                . ' no evidence parameter',
                $item,
            );
        }
        if (isset($seen[$constraint->evidence])) {
            throw typeFail(
                $state,
                "the checked boundary refuses `{$item->name}`: evidence `{$constraint->evidence}` names"
                . ' two dictionary parameters',
                $item,
            );
        }
        $seen[$constraint->evidence] = true;

        $evidence[] = [
            'wanted' => constraintKey($constraint),
            'class' => $constraint->class,
            'evidence' => $constraint->evidence,
            'origin' => $origin,
        ];
    }

    if (\count($evidence) !== $scheme->runtimeConstraintCount) {
        throw typeFail(
            $state,
            "the checked boundary refuses `{$origin}`: its scheme takes "
            . $scheme->runtimeConstraintCount . ' dictionaries but its evidence vector has '
            . \count($evidence),
            $item,
        );
    }

    return $evidence;
}

/**
 * The kind of each type parameter the published scheme binds.
 *
 * Kinds are declaration-local — the same name may be `Type` here and
 * `Type -> Type` in a sibling — so the answer is the one this declaration's own
 * signature scope fixed, read from the check that fixed it. A parameter the
 * scheme binds but no kind covers cannot be published: the record would state a
 * type parameter without saying what may be substituted for it.
 *
 * @return array<string, string>
 */
function parameterKindsFor(TypeCheckState $state, Ast\FunctionDecl $item, Scheme $scheme, string $module): array
{
    $fixedKinds = $state->checkedParameterKinds[\spl_object_id($item)] ?? null;
    if ($fixedKinds === null) {
        throw typeFail(
            $state,
            "the checked boundary refuses `{$module}::{$item->name}`: the check recorded no kind for"
            . ' the parameters of its signature',
            $item,
        );
    }

    $parameterKinds = [];
    foreach ($scheme->bound as $parameter) {
        if (! isset($fixedKinds[$parameter])) {
            throw typeFail(
                $state,
                "the checked boundary refuses `{$module}::{$item->name}`: the scheme binds"
                . " `{$parameter}` but the declaration's signature fixed no kind for it",
                $item,
            );
        }

        $parameterKinds[$parameter] = $fixedKinds[$parameter];
    }

    $kindErrors = Kinds\signatureKindErrors($state, $scheme->type, $parameterKinds);
    if ($kindErrors !== []) {
        throw typeFail(
            $state,
            "the checked boundary refuses `{$module}::{$item->name}`: {$kindErrors[0]}",
            $item,
        );
    }

    return $parameterKinds;
}

/**
 * Attach each recorded declaration's elaboration to its record.
 *
 * A declaration's own facts are settled at the end of the check, but the body
 * lowering receives is the one left by normalization, which rewrites it (strict
 * IO sequencing, eta-expanded IO bindings). The terms are built here, once the
 * tree is final, and each node they elaborate gets its term id — the one link
 * lowering follows. Every entry point that lowers a checked program calls this;
 * a record without it is a record of the wrong tree, and lowering refuses an
 * evidence site it has no term for.
 */
function publishElaboration(Ast\Program $program, ?\Moggi\Semantics\Types\TypeCheckState $state = null): Ast\Program
{
    $core = $program->core;
    if ($core === null) {
        return $program;
    }

    $declarations = [];
    foreach ($program->items as $item) {
        if ($item instanceof Ast\FunctionDecl && ! $item->signatureOnly) {
            $declarations[$item->name] = $item;
        }
    }

    foreach ($core->functions as $name => $record) {
        $declaration = $declarations[$name] ?? null;
        if ($declaration === null) {
            throw new \LogicException(
                "the checked core records `{$core->module}::{$name}` but the elaboration has no such"
                . ' lowered declaration',
            );
        }

        if ($state !== null) {
            assertObligationsSettled($declaration, $core->module, $state);
        }

        $termTypes = termTypesFor($declaration);
        $terms = checkedTermsIn($declaration);

        $core = $core->withFunction($record->withElaboration($termTypes, $terms));
    }

    $program->core = $core;

    return $program;
}

/**
 * Refuse an elaboration that still carries an unresolved obligation.
 *
 * The boundary asserts this over the body the check leaves behind, and
 * normalization then rewrites that body (strict IO sequencing, eta-expanded IO
 * bindings) before the record is attached to it. A body published with an
 * obligation still on it would be lowered from plans that never planned it, so
 * the last gate is the published tree itself, walked by the same
 * {@see walkElaboration()} the terms and plans come from — no shape is skipped
 * because someone forgot it in a match.
 */
function assertObligationsSettled(
    Ast\FunctionDecl $declaration,
    string $module,
    \Moggi\Semantics\Types\TypeCheckState $state,
): void {
    $refuse = static function (Ast\AstNode $node) use ($declaration, $module, $state): void {
        $pending = \Moggi\Semantics\Types\pendingConstraintsOf($state, $node);
        if ($pending === []) {
            return;
        }

        throw new \LogicException(
            "the elaboration of `{$module}::{$declaration->name}` still carries "
            . \count($pending) . ' unresolved obligation(s) on a '
            . $node::class . " at line {$node->line}; the checked core cannot publish a body it did not settle",
        );
    };

    foreach ($declaration->params as $param) {
        walkElaboration($param, $refuse);
    }
    walkElaboration($declaration->body, $refuse);
}

/**
 * The residual decisions this declaration carries.
 *
 * Today the one producer is numeric defaulting: it commits a variable the body
 * left ambiguous, and the record names the variable, the type it was defaulted
 * to, and the step that chose it. Anything else that settles an obligation
 * without unification belongs here, not in a comment.
 *
 * @return list<array{wanted: string, decision: string, provenance: string, origin: string}>
 */
function residualsFor(TypeCheckState $state, Ast\FunctionDecl $fn, string $origin): array
{
    $residuals = [];
    foreach ($state->defaultingLog as $entry) {
        if ($entry['decl'] !== \spl_object_id($fn)) {
            continue;
        }
        $residuals[] = [
            'wanted' => $entry['wanted'],
            'decision' => $entry['decision'],
            'provenance' => $entry['provenance'],
            'origin' => $origin,
        ];
    }

    return $residuals;
}

/** The identity of a wanted obligation: its class applied to its arguments. */
function constraintKey(Ast\PendingConstraint $constraint): string
{
    $args = \array_map(
        static fn (Type $arg): string => typeToString($arg),
        $constraint->args,
    );

    return $constraint->class . '(' . \implode(', ', $args) . ')';
}
