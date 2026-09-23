#!/usr/bin/env php
<?php declare(strict_types=1);

$root = __DIR__;
while (!\is_file($root . '/src/compiler.php') && \dirname($root) !== $root) {
    $root = \dirname($root);
}

require $root . '/src/compiler.php';

use Moggi\Docs;

$failures = 0;

function assertEq(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        ++$failures;
        fwrite(STDERR, "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
    }
}

function assertTrue(bool $value, string $label): void
{
    if (!$value) {
        global $failures;
        ++$failures;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

function parseModule(string $source, string $file = 'Test.mog'): \Moggi\Syntax\Ast\Program
{
    $tokens = \Moggi\Syntax\Lexer\lex($source, $file);

    return \Moggi\Syntax\Parser\parse($tokens, $source, $file);
}

$source = <<<'MOG'
-- | Module docs here.
module Docs.Fixture
  ( -- * Values
    id
  , map
  ) where

-- | Identity function.
id :: a -> a
id x = x

-- | Map over a list.
map :: (a -> b) -> [a] -> [b]
map f xs = xs
MOG;

$fixtureProgram = parseModule($source, 'Fixture.mog');
assertEq('Module docs here.', $fixtureProgram->moduleDoc, 'parser module doc');
assertEq('Identity function.', $fixtureProgram->items[0]->doc ?? null, 'parser function doc');
assertEq('Map over a list.', $fixtureProgram->items[1]->doc ?? null, 'parser map doc');

$eolSource = <<<'MOG'
module Docs.Eol where

twice :: (a -> a) -> a -> a  -- ^ Apply a function twice.
twice f x = f (f x)
MOG;

$eolProgram = parseModule($eolSource, 'Eol.mog');
assertEq('Apply a function twice.', $eolProgram->items[0]->doc ?? null, 'parser trailing doc');

$escaped = Docs\renderDocMarkup("See 'Maybe' and @List a@.");
assertEq(
    '<p>See <code>Maybe</code> and <code>List a</code>.</p>',
    $escaped,
    'markup',
);

$literalAt = Docs\renderDocMarkup('email user@host');
assertEq('<p>email user@host</p>', $literalAt, 'unmatched at-sign');

$haddockCode = Docs\renderDocMarkup('via {@code java.io.File} / {@code java.nio.file}');
assertEq(
    '<p>via <code>java.io.File</code> / <code>java.nio.file</code></p>',
    $haddockCode,
    'haddock code blocks',
);

$jvmDoc = Docs\renderDocMarkup('raw @java.lang.Object@ reference');
assertEq(
    '<p>raw <code>java.lang.Object</code> reference</p>',
    $jvmDoc,
    'paired at-monospace',
);

$magichash = Docs\renderDocMarkup("Uses 'intAdd#' for addition.");
assertEq(
    '<p>Uses <code>intAdd#</code> for addition.</p>',
    $magichash,
    'magichash ident',
);

$emph = Docs\renderDocMarkup('/emphasized/ text and __bold__ words');
assertTrue(str_contains($emph, '<em>emphasized</em>'), 'emph markup');
assertTrue(str_contains($emph, '<strong>bold</strong>'), 'bold markup');

$parserSource = <<<'MOG'
-- | Module from parser.
module Docs.ParserDoc
  ( -- * API
    greet
  ) where

-- | Say hello.
greet :: String -> String
greet s = s
MOG;

$parserProgram = parseModule($parserSource, 'ParserDoc.mog');
assertEq('Module from parser.', $parserProgram->moduleDoc, 'parser module doc header');
assertEq('Say hello.', $parserProgram->items[0]->doc ?? null, 'parser function doc header');

$exportSections = [];
foreach ($parserProgram->exports ?? [] as $export) {
    if (($export['name'] ?? '') === 'greet') {
        $exportSections[] = $export['section'] ?? null;
    }
}
assertEq('API', $exportSections[0] ?? null, 'export section from parser');

$xss = Docs\renderDocMarkup('<script>alert(1)</script>');
assertEq(
    '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    $xss,
    'html escape',
);

$exportSource = <<<'MOG'
module Docs.ExportTest
  ( publicFn
  , PublicType(PubCtor)
  ) where

privateFn :: Int -> Int
privateFn x = x

publicFn :: Int -> Int
publicFn x = x

data PublicType = PubCtor | HiddenCtor
MOG;

$program = parseModule($exportSource, 'ExportTest.mog');
$parsed = ['Docs.ExportTest' => new Docs\ParsedModule('Docs.ExportTest', 'ExportTest.mog', $exportSource, $program)];
$publicIndex = Docs\buildIndex($parsed);
$names = array_map(static fn ($e) => $e->name, $publicIndex->all);
assertTrue(in_array('publicFn', $names, true), 'export filter keeps publicFn');
assertTrue(in_array('PubCtor', $names, true), 'export filter keeps exported ctor');
assertTrue(!in_array('privateFn', $names, true), 'export filter hides privateFn');
assertTrue(!in_array('HiddenCtor', $names, true), 'export filter hides hidden ctor');

$classHomeSource = <<<'MOG'
module Docs.ClassHome where

-- | A type class.
class MyClass a where
  fn :: a -> a
MOG;

$classFacadeSource = <<<'MOG'
module Docs.ClassFacade
  ( MyClass(..)
  ) where

import Docs.ClassHome
MOG;

$classHome = parseModule($classHomeSource, 'ClassHome.mog');
$classFacade = parseModule($classFacadeSource, 'ClassFacade.mog');
assertEq(['MyClass'], Docs\reexportedClassNames($classFacade), 're-exported class names');
$classModules = [
    'Docs.ClassHome' => new Docs\ParsedModule('Docs.ClassHome', 'ClassHome.mog', $classHomeSource, $classHome),
    'Docs.ClassFacade' => new Docs\ParsedModule('Docs.ClassFacade', 'ClassFacade.mog', $classFacadeSource, $classFacade),
];
$classIndex = Docs\buildIndex($classModules);
$canonical = [];
foreach ($classIndex->all as $entity) {
    if ($entity->kind === 'class' && $entity->name === 'MyClass') {
        $canonical[Docs\canonicalKey($entity)] = $entity;
    }
}
$homes = Docs\reexportedClassHomes($classFacade, $canonical, 'Docs.ClassFacade');
assertEq('Docs.ClassHome', $homes[0]->module ?? null, 'class re-export resolves home module');

$fixture = __DIR__ . '/../fixture';
$index = Docs\loadOrBuildIndex($fixture, [], true);
$typeHits = Docs\search($index, 'a -> a', 5);
assertTrue(count($typeHits) > 0, 'type search finds id');
assertEq('id', $typeHits[0]->entity->name, 'type search top hit is id');

$listHit = Docs\search($index, '[a] -> [a]', 5);
assertTrue(count($listHit) > 0, 'list sugar type search');

$stringMiss = Docs\search($index, '[Char] -> Int', 5);
foreach ($stringMiss as $hit) {
    if ($hit->entity->name === 'id' && str_contains((string) $hit->entity->signature, 'String')) {
        assertTrue(false, 'String must not match [Char]');
    }
}

assertTrue(Docs\isBackendImplModule('Data.ByteString.PHP'), 'backend suffix detection');
assertTrue(!Docs\isBackendImplModule('Moggi.Internal.Prim'), 'internal is not backend impl suffix');

$moduleHits = Docs\search($index, 'is:module', 10);
assertTrue(count($moduleHits) > 0, 'is:module filter returns hits');
assertEq('module', $moduleHits[0]->entity->kind, 'is:module filter kind');

$sectionSource = <<<'MOG'
module Docs.SectionList
  ( alpha
    -- * Beta
  , beta
  ) where

alpha :: Int
alpha = 1

beta :: Int
beta = 2
MOG;

$sectionProgram = parseModule($sectionSource, 'SectionList.mog');
$betaSection = null;
foreach ($sectionProgram->exports ?? [] as $export) {
    if (($export['name'] ?? '') === 'beta') {
        $betaSection = $export['section'] ?? null;
    }
}
assertEq('Beta', $betaSection, 'export section without leading comma');

$classDocSource = <<<'MOG'
class HasDocs a where
  method :: a -> a
  -- | Between methods.
  other :: a -> a
MOG;

$classProgram = parseModule($classDocSource, 'HasDocs.mog');
$classDecl = $classProgram->items[0];
assertEq('Between methods.', $classDecl->methods[1]->doc ?? null, 'class method doc from parser');

\Moggi\Cache\setCacheEnabled(false);
$libProject = Docs\loadDocProject($root . '/lib');
\Moggi\Cache\setCacheEnabled(true);
$libIndex = Docs\buildIndex($libProject['modules'], $libProject['prepared']->checked, $libProject['prepared']);
assertTrue(isset($libIndex->byModule['Moggi.Internal.Prim']), 'Moggi.Internal.Prim is indexed');
$primNames = array_map(static fn ($e) => $e->name, $libIndex->byModule['Moggi.Internal.Prim']);
assertTrue(in_array('stringAppend#', $primNames, true), 'Prim exports stringAppend#');
$fixEntity = null;
foreach ($libIndex->byModule['Moggi.Internal.Prim'] as $entity) {
    if ($entity->name === 'fix#') {
        $fixEntity = $entity;
        break;
    }
}
assertTrue($fixEntity !== null, 'Prim exports fix#');
assertEq(
    'fix# :: (a -> a) -> a',
    $fixEntity?->signature,
    'higher-order signature parenthesizes its function argument',
);

$maybeHtml = Docs\renderModulePage(
    'Data.Maybe',
    $libIndex->byModule['Data.Maybe'],
    [],
    $libIndex,
);
assertTrue(str_contains($maybeHtml, 'data Maybe'), 'Maybe shows data signature');
assertTrue(str_contains($maybeHtml, 'Constructors'), 'Maybe shows Constructors subsection');
assertTrue(str_contains($maybeHtml, 'Instances'), 'Maybe shows Instances subsection');
assertTrue(str_contains($maybeHtml, 'Nothing'), 'Maybe lists Nothing ctor');
assertTrue(str_contains($maybeHtml, 'Just'), 'Maybe lists Just ctor');
assertTrue(str_contains($maybeHtml, 'instance Functor Maybe'), 'Maybe lists Functor instance');

$boolHtml = Docs\renderModulePage(
    'Data.Bool',
    $libIndex->byModule['Data.Bool'],
    [],
    $libIndex,
);
assertTrue(str_contains($boolHtml, 'data Bool'), 'Bool shows data head');
assertTrue(str_contains($boolHtml, 'Constructors'), 'Bool shows Constructors subsection');
assertTrue(str_contains($boolHtml, 'Instances'), 'Bool shows Instances subsection');
assertTrue(str_contains($boolHtml, 'instance Eq Bool'), 'Bool lists Eq instance');
assertTrue(str_contains($boolHtml, 'Operations'), 'Bool groups functions under Operations');
assertTrue(str_contains($boolHtml, 'infixr 3'), 'Bool shows && fixity');
assertTrue(str_contains($boolHtml, '# Source</a>'), 'Bool shows source links');

$stringHtml = Docs\renderModulePage(
    'Data.String',
    $libIndex->byModule['Data.String'],
    [],
    $libIndex,
);
assertTrue(substr_count($stringHtml, 'id="v-Data.String.lines"') === 1, 'lines appears once on Data.String');
assertTrue(str_contains($stringHtml, 'lines :: String -&gt; [String]'), 'lines uses list syntax');
assertTrue(!str_contains($stringHtml, 'operation lines'), 'no redundant operation label line');
assertTrue(!str_contains($stringHtml, 'type synonym String'), 'no redundant type synonym label line');

$ordHtml = Docs\renderModulePage(
    'Data.Ord',
    $libIndex->byModule['Data.Ord'],
    [],
    $libIndex,
);
assertTrue(str_contains($ordHtml, 'comparing :: Ord b =&gt;'), 'Ord comparing shows surface constraints');
assertTrue(!str_contains($ordHtml, '__Dict_'), 'Ord page hides compiler dict types');
assertTrue(substr_count($ordHtml, '# Source</a>') >= 5, 'Ord operations and methods show source links');
assertTrue(str_contains($ordHtml, 'id="v-Data.Ord.comparing"'), 'Ord comparing is indexed');
assertTrue(str_contains($ordHtml, 'src/Data-Ord.mog.html#L20'), 'Ord comparing links to source line');

$dictScheme = new \Moggi\Semantics\TypeExpr\Scheme(
    new \Moggi\Semantics\TypeExpr\TArrow(
        new \Moggi\Semantics\TypeExpr\TCon('__Dict_Eq'),
        new \Moggi\Semantics\TypeExpr\TArrow(
            new \Moggi\Semantics\TypeExpr\TCon('__Dict_Ord'),
            new \Moggi\Semantics\TypeExpr\TArrow(
                new \Moggi\Semantics\TypeExpr\TVar('a'),
                new \Moggi\Semantics\TypeExpr\TCon('Ordering'),
            ),
        ),
    ),
    [],
    [],
    2,
    [],
);
assertTrue(!str_contains(Docs\schemeToString($dictScheme), '__Dict_'), 'schemeToString hides dict arrows');

$appSource = <<<'MOG'
module Docs.ApplicativeFixture where

class Functor f => Applicative f where
  pure :: a -> f a
  (<*>) :: f (a -> b) -> f a -> f b

class Applicative f => Alternative f where
  empty :: f a
  (<|>) :: f a -> f a -> f a

(*>) :: Applicative f => f a -> f b -> f b
(*>) fa fb = fa

(<*) :: Applicative f => f a -> f b -> f a
(<*) fa fb = fa
MOG;

$appProgram = parseModule($appSource, 'ApplicativeFixture.mog');
$appIndex = Docs\buildIndex([
    'Docs.ApplicativeFixture' => new Docs\ParsedModule(
        'Docs.ApplicativeFixture',
        'ApplicativeFixture.mog',
        $appSource,
        $appProgram,
    ),
]);
$appHtml = Docs\renderModulePage(
    'Docs.ApplicativeFixture',
    $appIndex->byModule['Docs.ApplicativeFixture'],
    [],
    $appIndex,
);
assertTrue(str_contains($appHtml, 'class Applicative f =&gt; Alternative f where'), 'class shows full header');
assertTrue(str_contains($appHtml, 'empty :: f a'), 'class method signature under class');
assertTrue(!str_contains($appHtml, 'methodempty'), 'no cramped method label');
assertTrue(str_contains($appHtml, 'Operations'), 'top-level ops grouped under Operations');
assertTrue(str_contains($appHtml, '*&gt; :: Applicative f'), 'top-level ops show signature');
assertTrue(isset($libIndex->byModule['Prelude']), 'Prelude module page is indexed');
assertTrue(count($libIndex->byModule['Prelude']) > 10, 'Prelude lists re-exported symbols');
assertTrue(isset($libIndex->byModule['Data.String']), 'facade modules stay indexed');

$bsBackends = $libIndex->facadeBackends['Data.ByteString'] ?? [];
assertTrue(isset($bsBackends['php'], $bsBackends['jvm'], $bsBackends['dotnet']), 'Data.ByteString backend map');
$bsHtml = Docs\renderModulePage(
    'Data.ByteString',
    $libIndex->byModule['Data.ByteString'],
    [],
    $libIndex,
);
assertTrue(str_contains($bsHtml, 'Backends:'), 'facade page shows backends');
assertTrue(str_contains($bsHtml, 'PHP · JVM · .NET'), 'facade shows backend labels without links');
assertTrue(!str_contains($bsHtml, 'Data-ByteString-PHP.html'), 'facade does not link backend impl pages');
assertTrue(!isset($libIndex->byModule['Data.ByteString.PHP']), 'backend impl modules are not indexed');
assertTrue(!isset($libIndex->byModule['Data.String.PHP']), 'backend impl modules are not indexed');
assertTrue(!isset($libIndex->byModule['Platform.JVM']), 'backend impl modules are not indexed');

$semiHtml = Docs\renderModulePage(
    'Data.Semigroup',
    $libIndex->byModule['Data.Semigroup'],
    [],
    $libIndex,
);
$maxPos = strpos($semiHtml, 'id="v-Data.Semigroup.Max"');
$maxCtorPos = strpos($semiHtml, 'id="v-Data.Semigroup.Max.Max"');
$getMaxPos = strpos($semiHtml, 'id="v-Data.Semigroup.getMax"');
assertTrue($maxPos !== false && $getMaxPos !== false, 'Semigroup Max/getMax anchors exist');
assertTrue($maxCtorPos !== false, 'Semigroup Max constructor has distinct anchor');
assertEq(1, substr_count($semiHtml, 'id="v-Data.Semigroup.Max"'), 'data Max anchor is unique');
$instancesPos = strpos($semiHtml, 'Instances', $maxPos);
assertTrue($instancesPos !== false && $instancesPos < $getMaxPos, 'Max instances stay under Max data');
assertTrue(
    substr_count(
        substr($semiHtml, $getMaxPos, $getMaxPos > 0 ? 600 : 0),
        'Instances',
    ) === 0,
    'getMax block does not include Max instances',
);

$emptyIndex = Docs\indexFromJson([
    'version' => Docs\docIndexSchemaVersion(),
    'modules' => [],
]);
assertEq(0, count($emptyIndex->search), 'indexFromJson tolerates missing search key');

assertTrue(
    Docs\pathIsUnderDocRoot('/tmp/docs/index.html', '/tmp/docs'),
    'pathIsUnderDocRoot accepts files under root',
);
assertTrue(
    !Docs\pathIsUnderDocRoot('/etc/passwd', '/tmp/docs'),
    'pathIsUnderDocRoot rejects paths outside root',
);

$collisionIndex = Docs\buildIndex([
    'Docs.One' => new Docs\ParsedModule(
        'Docs.One',
        'One.mog',
        '',
        parseModule(<<<'MOG'
{-# LANGUAGE NoImplicitPrelude #-}
module Docs.One where
import Data.Int
x :: Int
x = 1
MOG, 'One.mog'),
    ),
    'Docs.Two' => new Docs\ParsedModule(
        'Docs.Two',
        'Two.mog',
        '',
        parseModule(<<<'MOG'
{-# LANGUAGE NoImplicitPrelude #-}
module Docs.Two where
import Data.Int
x :: Int
x = 2
MOG, 'Two.mog'),
    ),
]);
$collisionTargets = Docs\buildLinkTargets($collisionIndex);
assertTrue(!isset($collisionTargets['x']), 'ambiguous bare link targets are omitted');
assertTrue(isset($collisionTargets['Docs.One.x']), 'qualified link target kept for Docs.One.x');
assertTrue(isset($collisionTargets['Docs.Two.x']), 'qualified link target kept for Docs.Two.x');

$extraLibDir = __DIR__ . '/fixture-extra';
$appDir = __DIR__ . '/fixture-app';
$appProject = Docs\loadDocProject($appDir, [$extraLibDir]);
assertTrue(isset($appProject['modules']['Lib.Extra']), 'loadDocProject resolves --lib modules');
assertTrue(isset($appProject['modules']['Docs.App']), 'loadDocProject indexes app modules');

foreach ($libIndex->byModule['Prelude'] as $entity) {
    if ($entity->name === 'map' && $entity->kind === 'value') {
        assertTrue(str_starts_with($entity->href, 'Prelude.html#'), 'Prelude re-export href stays on facade page');
        break;
    }
}

$preludeMaybeHtml = Docs\renderModulePage(
    'Prelude',
    $libIndex->byModule['Prelude'],
    Docs\buildLinkTargets($libIndex),
    $libIndex,
);
assertTrue(str_contains($preludeMaybeHtml, 'Constructors'), 'Prelude re-exported types include constructors');
assertTrue(str_contains($preludeMaybeHtml, 'Just'), 'Prelude Maybe shows Just constructor');

$instanceDocSource = <<<'MOG'
module Docs.InstanceDoc where

class C a where
  m :: a -> a

instance C Int where
  -- | Instance method documentation.
  m x = x
MOG;
$instanceDocProgram = parseModule($instanceDocSource, 'InstanceDoc.mog');
$instanceDecl = $instanceDocProgram->items[1];
assertEq('Instance method documentation.', $instanceDecl->methods[0]->doc ?? null, 'instance method doc');

$sectionModuleSource = <<<'MOG'
module Docs.SectionBody where

-- * Operations
-- | Increment by one.
inc x = x
MOG;
$sectionModuleProgram = parseModule($sectionModuleSource, 'SectionBody.mog');
assertEq('Increment by one.', $sectionModuleProgram->items[0]->doc ?? null, 'section heading before top-level doc');

$splitDocSource = <<<'MOG'
module Docs.SplitDoc where

foo :: Int -> Int  -- ^ Signature note.

-- | Body note.
foo x = x
MOG;
$splitDocProgram = parseModule($splitDocSource, 'SplitDoc.mog');
assertEq("Signature note.\nBody note.", $splitDocProgram->items[0]->doc ?? null, 'split sig/body docs merge');

$classTrailingSource = <<<'MOG'
module Docs.ClassTrailing where

class C a where
  m :: a -> a  -- ^ Trailing method doc.
MOG;
$classTrailingProgram = parseModule($classTrailingSource, 'ClassTrailing.mog');
assertEq('Trailing method doc.', $classTrailingProgram->items[0]->methods[0]->doc ?? null, 'class method trailing doc');

$moduleDocProgram = parseModule("-- | Module level.\nmodule Docs.ModDoc where\n", 'ModDoc.mog');
assertEq('Module level.', $moduleDocProgram->moduleDoc, 'module doc parsed');

$program = new \Moggi\Syntax\Ast\Program(
    [],
    'M',
    moduleDoc: 'docs',
    line: 9,
    col: 1,
    endCol: 2,
);
assertEq('docs', $program->moduleDoc, 'Program moduleDoc positional after named fields');
assertEq(9, $program->line, 'Program line preserved after moduleDoc field');

$ctorLink = Docs\resolveDocLink('Data.Semigroup#Max.Max', []);
assertTrue(
    $ctorLink !== null && str_contains($ctorLink, '#v-Data.Semigroup.Max.Max'),
    'resolveDocLink uses ctor-qualified anchors',
);

$tmp = tempnam(sys_get_temp_dir(), 'moggi-fp-');
file_put_contents($tmp, "module A where\n");
$fp1 = Docs\docSourceFingerprint([$tmp]);
file_put_contents($tmp, "module A where\nx = 1\n");
$fp2 = Docs\docSourceFingerprint([$tmp]);
assertTrue($fp1 !== $fp2, 'docSourceFingerprint tracks content changes');
@unlink($tmp);

try {
    \Moggi\Syntax\Lexer\lex("{-| unterminated", 'Bad.mog');
    assertTrue(false, 'unterminated block doc should error');
} catch (\Moggi\Syntax\Lexer\LexError) {
    // expected
}

$importDocSource = <<<'MOG'
module Docs.ImportDoc where

-- | Docs before import should not become declarations.
import Prelude

x = 1
MOG;
$importDocProgram = parseModule($importDocSource, 'ImportDoc.mog');
assertEq(1, count($importDocProgram->imports), 'doc before import stays in imports');
assertEq(1, count($importDocProgram->items), 'doc before import is not a top-level item');

$ctorDocSource = <<<'MOG'
module Docs.CtorDoc where

data Tree a
  = -- | Empty tree.
    Leaf
  | -- | Branch node.
    Node a (Tree a) (Tree a)
MOG;
$ctorDocProgram = parseModule($ctorDocSource, 'CtorDoc.mog');
$dataDecl = $ctorDocProgram->items[0];
assertEq('Empty tree.', $dataDecl->constructors[0]->doc ?? null, 'constructor leading doc');
assertEq('Branch node.', $dataDecl->constructors[1]->doc ?? null, 'second constructor doc');

$instanceHeadDocSource = <<<'MOG'
module Docs.InstanceHeadDoc where

class C a where
  m :: a -> a

-- | Instance for Integers.
instance C Int where
  m x = x
MOG;
$instanceHeadDocProgram = parseModule($instanceHeadDocSource, 'InstanceHeadDoc.mog');
assertEq('Instance for Integers.', $instanceHeadDocProgram->items[1]->doc ?? null, 'instance head doc');

$fixityDocSource = <<<'MOG'
module Docs.FixityDoc where

-- | Left-associative addition.
infixl 6 +
MOG;
$fixityDocProgram = parseModule($fixityDocSource, 'FixityDoc.mog');
assertEq('Left-associative addition.', $fixityDocProgram->fixityDocs[0]['doc'] ?? null, 'fixity doc preserved');
$fixityIndexModules = [
    'Docs.FixityDoc' => new Docs\ParsedModule(
        'Docs.FixityDoc',
        'FixityDoc.mog',
        $fixityDocSource,
        $fixityDocProgram,
    ),
];
$fixityIndex = Docs\buildIndex($fixityIndexModules);
$fixityModuleHtml = Docs\renderModulePage(
    'Docs.FixityDoc',
    $fixityIndex->byModule['Docs.FixityDoc'],
    Docs\buildLinkTargets($fixityIndex),
    $fixityIndex,
);
assertTrue(str_contains($fixityModuleHtml, 'Left-associative addition.'), 'fixity doc appears on module page');
assertTrue(str_contains($fixityModuleHtml, 'infixl 6 +'), 'fixity declaration appears on module page');

$reexportHome = Docs\makeEntity(
    'Docs.ReexportDoc',
    'home',
    'value',
    'home :: Int',
    'Home documentation.',
    null,
);
$reexportCopy = Docs\copyForReexport($reexportHome, 'Docs.ReexportFacade', null);
assertEq('Home documentation.', $reexportCopy->doc, 're-export preserves home doc');

$aliasEntity = null;
foreach ($libIndex->byModule['Data.Maybe'] ?? [] as $entity) {
    if ($entity->name === 'Maybe' && $entity->kind === 'type') {
        $aliasEntity = $entity;
        break;
    }
}
assertTrue(in_array('Prelude', $aliasEntity?->aliases ?? [], true), 'home module shows re-export alias');

$homonymOne = <<<'MOG'
module Docs.HomonymOne where
x :: Int
x = 1
MOG;
$homonymTwo = <<<'MOG'
module Docs.HomonymTwo where
x :: Bool
x = True
MOG;
$homonymModules = [
    'Docs.HomonymOne' => new Docs\ParsedModule(
        'Docs.HomonymOne',
        'HomonymOne.mog',
        $homonymOne,
        parseModule($homonymOne, 'HomonymOne.mog'),
    ),
    'Docs.HomonymTwo' => new Docs\ParsedModule(
        'Docs.HomonymTwo',
        'HomonymTwo.mog',
        $homonymTwo,
        parseModule($homonymTwo, 'HomonymTwo.mog'),
    ),
];
$homonymIndex = Docs\buildIndex($homonymModules);
$homonymNames = array_values(array_filter(
    $homonymIndex->search,
    static fn ($entity) => $entity->name === 'x' && $entity->kind === 'value',
));
assertEq(2, count($homonymNames), 'search keeps homonymous values in different modules');

$foreignFilterRows = [
    ['name' => 'prim', 'kind' => 'primop', 'module' => 'M.P', 'signature' => ''],
    ['name' => 'fn', 'kind' => 'value', 'module' => 'M.V', 'signature' => ''],
    ['name' => 'ext', 'kind' => 'foreign', 'module' => 'M.F', 'signature' => ''],
];
$foreignHits = Docs\searchInRows($foreignFilterRows, 'is:value', 10);
$foreignKinds = array_map(static fn ($hit) => $hit['entity']['kind'], $foreignHits);
sort($foreignKinds);
assertEq(['foreign', 'primop', 'value'], $foreignKinds, 'is:value includes foreign and primop');

$ambiguousTargets = [
    'Docs.One.x' => 'One.html',
    'Docs.Two.x' => 'Two.html',
];
assertEq('x', Docs\qualifiedNameFromIdent('x', $ambiguousTargets), 'ambiguous qualified link target omitted');

$synonymSource = <<<'MOG'
module Docs.SynonymSource where

-- | A synonym.
type MyInt = Int
MOG;
$synonymProgram = parseModule($synonymSource, 'SynonymSource.mog');
$synonymModules = [
    'Docs.SynonymSource' => new Docs\ParsedModule(
        'Docs.SynonymSource',
        'SynonymSource.mog',
        $synonymSource,
        $synonymProgram,
    ),
];
$synonymIndex = Docs\buildIndex($synonymModules);
$synonymEntity = null;
foreach ($synonymIndex->all as $entity) {
    if ($entity->name === 'MyInt') {
        $synonymEntity = $entity;
        break;
    }
}
assertTrue($synonymEntity?->sourceLine !== null, 'type synonym has source line');
assertTrue($synonymEntity?->sourcePath !== null, 'type synonym has source path');

$multilineTrailingSource = <<<'MOG'
module Docs.TrailingMultiline where

f x = x  -- ^ First trailing line.
-- ^ Second trailing line.
MOG;
$multilineTrailingProgram = parseModule($multilineTrailingSource, 'TrailingMultiline.mog');
assertEq(
    "First trailing line.\nSecond trailing line.",
    $multilineTrailingProgram->items[0]->doc ?? null,
    'multi-line -- ^ trailing doc',
);

$classBetweenTrailingSource = <<<'MOG'
module Docs.ClassBetweenTrailing where

class C a where
  m :: a -> a
  -- ^ Between methods doc.
  n :: a -> a
MOG;
$classBetweenTrailingProgram = parseModule($classBetweenTrailingSource, 'ClassBetweenTrailing.mog');
$classBetween = $classBetweenTrailingProgram->items[0];
assertEq('Between methods doc.', $classBetween->methods[0]->doc ?? null, 'class trailing doc attaches to prior method');

$instanceBetweenTrailingSource = <<<'MOG'
module Docs.InstanceBetweenTrailing where

class C a where
  m :: a -> a

instance C Int where
  m x = x
  -- ^ Trailing instance method doc.
  n x = x
MOG;
$instanceBetweenTrailingProgram = parseModule($instanceBetweenTrailingSource, 'InstanceBetweenTrailing.mog');
$instanceBetween = $instanceBetweenTrailingProgram->items[1];
assertEq('Trailing instance method doc.', $instanceBetween->methods[0]->doc ?? null, 'instance trailing doc between methods');

$instanceBoundarySource = <<<'MOG'
module Docs.InstanceBoundary where

class C a where
  m :: a -> a

instance C Int where
  m x = x

-- | Top-level after instance.
top :: Int
top = 1
MOG;
$instanceBoundaryProgram = parseModule($instanceBoundarySource, 'InstanceBoundary.mog');
assertEq(3, count($instanceBoundaryProgram->items), 'instance body stops before module-column doc');
assertEq('Top-level after instance.', $instanceBoundaryProgram->items[2]->doc ?? null, 'doc after instance binds top-level decl');

$instanceIndexSource = <<<'MOG'
module Docs.InstanceIndex where

class C a where
  m :: a -> a

-- | Indexed instance head doc.
instance C Int where
  m x = x
MOG;
$instanceIndexModules = [
    'Docs.InstanceIndex' => new Docs\ParsedModule(
        'Docs.InstanceIndex',
        'InstanceIndex.mog',
        $instanceIndexSource,
        parseModule($instanceIndexSource, 'InstanceIndex.mog'),
    ),
];
$instanceIndex = Docs\buildIndex($instanceIndexModules);
$indexedInstance = null;
foreach ($instanceIndex->all as $entity) {
    if ($entity->kind === 'instance') {
        $indexedInstance = $entity;
        break;
    }
}
assertEq('Indexed instance head doc.', $indexedInstance?->doc, 'buildIndex indexes instance head docs');

$checkInstanceDocSource = <<<'MOG'
module Docs.CheckInstanceDoc where

class C a where
  m :: a -> a

instance C Int where
  -- | Preserved through typecheck.
  m x = x
MOG;
$checkInstanceDocProgram = parseModule($checkInstanceDocSource, 'CheckInstanceDoc.mog');
$checkedInstanceDocProgram = \Moggi\Semantics\Types\checkRaw($checkInstanceDocProgram, $checkInstanceDocSource, 'CheckInstanceDoc.mog');
$checkedMethodDoc = null;
foreach ($checkedInstanceDocProgram->items as $item) {
    if ($item instanceof \Moggi\Syntax\Ast\FunctionDecl && $item->name === 'm' && $item->instanceMethod) {
        $checkedMethodDoc = $item->doc;
        break;
    }
}
assertEq('Preserved through typecheck.', $checkedMethodDoc, 'checkInstance copies method docs');

$mapNameHits = Docs\search($libIndex, 'map', 10);
$mapNames = array_map(static fn ($hit) => $hit->entity->name, $mapNameHits);
assertTrue(in_array('map', $mapNames, true), 'short name query finds map via name search');

$idNameHits = Docs\search($index, 'id', 5);
assertTrue(count($idNameHits) > 0 && $idNameHits[0]->entity->name === 'id', 'single-token name query is not misrouted to type search');

$uniqueTargets = ['Docs.Only.foo' => 'Only.html'];
assertEq('Docs.Only.foo', Docs\qualifiedNameFromIdent('foo', $uniqueTargets), 'unambiguous qualified link target resolves');

$serveRoot = sys_get_temp_dir() . '/moggi-serve-' . getmypid();
@mkdir($serveRoot, 0777, true);
file_put_contents($serveRoot . '/index.html', '<html></html>');
assertEq($serveRoot . '/index.html', Docs\resolveStaticDocPath($serveRoot, '/'), 'serve resolves / to index.html');
assertEq($serveRoot . '/index.html', Docs\resolveStaticDocPath($serveRoot, '/index.html'), 'serve resolves existing static file');
assertEq('', Docs\resolveStaticDocPath($serveRoot, '/missing.css'), 'serve 404 for missing asset with extension');
assertEq(null, Docs\resolveStaticDocPath($serveRoot, '/Docs-Fixture'), 'serve falls through for extensionless paths');
@unlink($serveRoot . '/index.html');
@rmdir($serveRoot);

$cacheWorkDir = sys_get_temp_dir() . '/moggi-doc-cache-' . getmypid();
$cacheFixtureDir = $cacheWorkDir . '/fixture';
@mkdir($cacheFixtureDir . '/Docs', 0777, true);
$cacheFixtureMog = $cacheFixtureDir . '/Docs/Fixture.mog';
copy($fixture . '/Docs/Fixture.mog', $cacheFixtureMog);
$prevCacheDir = getenv('MOGGI_CACHE_DIR') ?: '';
putenv('MOGGI_CACHE_DIR=' . $cacheWorkDir);
$cachePath = Docs\indexCachePath($cacheFixtureDir, []);
Docs\loadOrBuildIndex($cacheFixtureDir, [], true);
assertTrue(is_file($cachePath), 'doc index cache file created');
$cacheMtimeBefore = filemtime($cachePath) ?: 0;
$fixtureSource = (string) file_get_contents($cacheFixtureMog);
usleep(1_100_000);
touch($cacheFixtureMog);
Docs\loadOrBuildIndex($cacheFixtureDir, [], false);
$cacheMtimeAfter = filemtime($cachePath) ?: 0;
assertEq($cacheMtimeBefore, $cacheMtimeAfter, 'doc cache survives mtime-only source change');
file_put_contents($cacheFixtureMog, rtrim($fixtureSource) . "\n-- touch\n");
Docs\loadOrBuildIndex($cacheFixtureDir, [], false);
$cacheMtimeRebuild = filemtime($cachePath) ?: 0;
assertTrue($cacheMtimeRebuild > $cacheMtimeAfter, 'doc cache rebuilds when source content changes');

$corruptCachePath = Docs\indexCachePath($cacheFixtureDir, []);
file_put_contents($corruptCachePath, '{"version":999,"broken":true}');
Docs\loadOrBuildIndex($cacheFixtureDir, [], false);
$rebuiltCache = json_decode((string) file_get_contents($corruptCachePath), true);
assertEq(Docs\docIndexSchemaVersion(), $rebuiltCache['version'] ?? 0, 'doc cache rebuilds when cache JSON is invalid');
assertTrue(isset($rebuiltCache['modules']), 'rebuilt doc cache contains modules');

$sourceFingerprint = '';
Docs\loadOrBuildIndex($cacheFixtureDir, [], false, $sourceFingerprint);
assertTrue($sourceFingerprint !== '', 'loadOrBuildIndex returns source fingerprint on cache hit');
$secondFingerprint = '';
Docs\loadOrBuildIndex($cacheFixtureDir, [], false, $secondFingerprint);
assertEq($sourceFingerprint, $secondFingerprint, 'source fingerprint stable across cache hits');

$malformedCachePath = Docs\indexCachePath($cacheFixtureDir, []);
file_put_contents($malformedCachePath, '{"version":1,"modules":{}}');
Docs\loadOrBuildIndex($cacheFixtureDir, [], false);
$malformedCache = json_decode((string) file_get_contents($malformedCachePath), true);
assertTrue(isset($malformedCache['search']), 'doc cache rebuilds when same-version JSON is malformed');

$eitherIndex = Docs\buildIndex(
    ['Data.Either' => $libProject['modules']['Data.Either']],
    ['Data.Either' => $libProject['prepared']->checked['Data.Either']],
    $libProject['prepared'],
);
foreach ($eitherIndex->byModule['Data.Either'] ?? [] as $entity) {
    if ($entity->name === 'either') {
        // The signature `Data.Either` writes, with its own variables and its
        // arrow domain parenthesised.
        assertTrue(
            str_contains((string) $entity->signature, '(a -> c) -> (b -> c) -> Either a b -> c'),
            'a written signature renders as written: ' . $entity->signature,
        );
    }
    if ($entity->name === 'isLeft') {
        assertTrue(str_contains((string) $entity->signature, 'Either a b'), 'isLeft uses friendly type variables');
    }
    // Instance methods have no written signature, so theirs is inferred — and an
    // inferred one must carry friendly letters, never compiler-internal names.
    assertTrue(
        preg_match('/\bt\d+\b/', (string) $entity->signature) !== 1,
        'signatures hide compiler t-vars: ' . $entity->name . ' :: ' . $entity->signature,
    );
}

$preludeOps = Docs\buildIndex(
    ['Prelude' => $libProject['modules']['Prelude']],
    ['Prelude' => $libProject['prepared']->checked['Prelude']],
    $libProject['prepared'],
);
foreach ($preludeOps->byModule['Prelude'] ?? [] as $entity) {
    if ($entity->name === '!!') {
        assertTrue(str_contains((string) $entity->signature, '[a] -> Int -> a'), '!! signature uses friendly list syntax');
        assertTrue(!str_contains((string) $entity->signature, 'infixl'), 'fixity is not embedded in signature text');
        assertEq('infixl 9', $entity->fixity, 'fixity stored separately');
    }
}

$exportCommaSource = <<<'MOG'
module Bad.Export ( alpha beta ) where

alpha = 1
beta = 2
MOG;

$exportCommaFailed = false;
try {
    parseModule($exportCommaSource, 'BadExport.mog');
} catch (\Moggi\Syntax\Parser\ParseError) {
    $exportCommaFailed = true;
}
assertTrue($exportCommaFailed, 'export list rejects missing commas');

$fixityTrailingSource = <<<'MOG'
module Docs.FixityTrailing where

infixl 6 + -- ^ Left-associative addition.
MOG;

$fixityTrailingProgram = parseModule($fixityTrailingSource, 'FixityTrailing.mog');
assertEq('Left-associative addition.', $fixityTrailingProgram->fixityDocs[0]['doc'] ?? null, 'fixity trailing doc');

$interImportDocSource = <<<'MOG'
module Docs.InterImportDoc where

import Data.Bool
-- | Documents the next import.
import Data.Eq
MOG;

$interImportDocProgram = parseModule($interImportDocSource, 'InterImportDoc.mog');
assertEq('Documents the next import.', $interImportDocProgram->imports[1]->doc ?? null, 'inter-import docs attach to following import');

if ($prevCacheDir !== '') {
    putenv('MOGGI_CACHE_DIR=' . $prevCacheDir);
} else {
    putenv('MOGGI_CACHE_DIR');
}
@unlink($cachePath);
@unlink($cacheFixtureMog);
@rmdir($cacheFixtureDir . '/Docs');
@rmdir($cacheFixtureDir);
@rmdir($cacheWorkDir);

if ($failures === 0) {
    echo "docs unit: ok\n";
    exit(0);
}

exit(1);
