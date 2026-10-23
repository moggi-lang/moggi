<?php declare(strict_types=1);

namespace Moggi\Syntax\Parser;

use Moggi\Syntax\Lexer\TokenKind;

use function Moggi\Errors\formatDiagnostic;

/**
 * Lexer token. Replaces the previous `['kind'=>TokenKind,'lexeme'=>…,'line'=>…,'col'=>…]` arrays.
 *
 * `TokenKind` stays in `syntax/lexer.php`; this file only defines the token wrapper.
 */
final class Token
{
    public function __construct(
        public readonly TokenKind $kind,
        public readonly string $lexeme,
        public readonly int $line,
        public readonly int $col,
        public readonly ?int $endCol = null,
    ) {
    }
}

/**
 * Mutable parser cursor threaded through the syntax parsers.
 *
 * Ephemeral flags (case/do/guard boundaries, backend map) live on the same
 * object so they share the handle without `&$state` array mutation.
 */
final class ParserState
{
    /**
     * @param list<Token> $tokens
     * @param array<string, array{assoc: string, prec: int}> $fixity
     * @param array<string, mixed> $backendMap
     */
    public function __construct(
        public array $tokens,
        public int $pos = 0,
        public array $fixity = [],
        public string $source = '',
        public string $filename = '',
        public array $backendMap = [],
        public bool $stopBeforeGuardClause = false,
        public ?int $guardRhsLine = null,
        public bool $parsingCaseScrutinee = false,
        public bool $inDoBlock = false,
        public ?int $doBlockCol = null,
        public ?int $doExprStartLine = null,
        public bool $inCaseAltPattern = false,
        /**
         * A guard expression is an expression, so a declaration can never begin
         * inside it. Without this, `| positive n = "yes"` reads `n` as the start
         * of a top-level `n = …` and ends the guard's application early.
         */
        public bool $parsingGuardExpr = false,
        public bool $stopBeforeCaseAlt = false,
        public ?int $caseAltBodyLine = null,
        public ?int $caseAltCol = null,
        public ?int $stopApplyAtLine = null,
        /**
         * Column of the first token of the top-level item being parsed (0 when
         * no item is open, e.g. header-only parses). Offside-continuation
         * keywords (`in`, `where`) may never be dedented to this column: doing
         * so ends the declaration instead of continuing it.
         */
        public int $declCol = 0,
    ) {
    }

    /**
     * Bracket depth before each token, built on first use and cached for the
     * token list. A declaration can never begin inside brackets, so the
     * declaration heuristics read this to reject shapes that only look like a
     * declaration from inside an expression — a list comprehension's
     * `[head ys | ys <- xss]` has the same `name arg… |` prefix as a guarded
     * clause `head ys | ys > 0 = …`.
     *
     * @var list<int>|null
     */
    public ?array $bracketDepthBefore = null;
}

final class ParseError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $filename,
        public readonly string $source,
        private int $errorLine,
        private int $errorCol,
        private int $errorEndCol = 0,
        public readonly string $diagnosticCode = 'parse',
    ) {
        parent::__construct($message);
    }

    public function display(): string
    {
        return formatDiagnostic(
            $this->filename,
            'parse error',
            $this->getMessage(),
            $this->source,
            $this->errorLine,
            $this->errorCol,
            $this->errorEndCol,
        );
    }

    public function line(): int
    {
        return $this->errorLine;
    }

    public function col(): int
    {
        return $this->errorCol;
    }

    public function endCol(): int
    {
        return $this->errorEndCol;
    }
}
