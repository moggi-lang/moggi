# Deriving

Moggi supports four deriving strategies:
`stock`, `newtype` (generalized newtype deriving), `anyclass`, and `via`.

## Strategies

### Stock (default)

Generates instance methods by structural pattern matching on the ADT.

```moggi
data Color = Red | Green | Blue
  deriving (Eq, Ord, Show, Enum, Bounded)

-- Equivalent to:
-- deriving stock (Eq, Ord, Show, Enum, Bounded)
```

Supported classes: `Eq`, `Ord`, `Enum`, `Bounded`, `Show`, `Read`, `Functor`,
`Foldable`, `Traversable`, `Generic`.

### Newtype (Generalized Newtype Deriving)

Coerces the underlying type's instance. Only works for `newtype` declarations.

```moggi
newtype Age = Age Int
  deriving (Eq, Ord, Show)
  deriving newtype (Num)

-- Num Age reuses Num Int via coercion
```

The generated methods unwrap the newtype constructor for arguments and re-wrap
the result, so the class parameter is converted to the representation type:

```moggi
newtype Age = Age Int
  deriving newtype (Num)

-- Equivalent to:
-- instance Num Age where
--   (+) (Age a) (Age b) = Age (a + b)
--   ...
```

GND requires the class parameter to appear as a bare type variable in every
method signature (`a`, not `m a`). The higher-kinded classes `Functor`,
`Foldable`, and `Traversable` are handled separately and may use `m a` / `f a`
shapes.

### Anyclass

Builds the instance from the class's default method implementations. Methods
that have a default are filled in from the class; methods that have none become
an `error` body, so the instance is still complete and only *calling* such a
method fails.

```moggi
class Describe a where
  describe :: a -> String
  describe _ = "something"

data Foo = Foo
  deriving (Eq, Show)
  deriving anyclass (Describe)

-- Generates an instance whose method bodies come from the class defaults.
```

```moggi
class Peek a where
  peek :: a -> Int        -- no default

data Bar = Bar
  deriving anyclass (Peek)  -- ok; `peek` becomes an error thunk
```

Deriving an instance does not require every method to have a default. The
`error` body is only reached if the method is actually called.

### Via

Derives an instance by delegating to a *representationally compatible* type.

```moggi
import Data.Eq

newtype MySum = MySum Int
  deriving (Eq)

newtype MyInt = MyInt Int
  deriving via (MySum) Eq
```

The gate is representation compatibility, not a particular shape of target:
both the target and the via type are unwrapped through their newtype chains, and
the resulting representations must be equal. A plain `data` type is its own
representation, so a data target is allowed as long as some via newtype wraps
it:

```moggi
data T = T Int

newtype TV = TV T
instance MyEq TV where ...

-- T unwraps to T; TV unwraps to T — compatible.
deriving via (TV) instance MyEq T
```

```moggi
newtype Inner = Inner Int
  deriving (Eq)

newtype Outer = Outer Inner
  deriving via (Int) Eq   -- Outer -> Inner -> Int

data Wrapped = Wrapped Int
  deriving via (Int) Eq   -- rejected: Wrapped unwraps to itself, not to Int
```

`deriving via (V) C` produces `instance C V => C T`. Class methods whose
parameter is `T` at the top level are coerced explicitly — arguments are
unwrapped from `T` and re-wrapped as `V`, and results are unwrapped from `V`
and re-wrapped as `T`. Newtypes are erased by the backends, so these
conversions are type-level; they exist to route dispatch through the `C V`
context.

Visibility of the via type follows ordinary module visibility; there is no
deriving-specific restriction. A private via type works inside the module that
defines the derived instance, because the `C V` context is discharged there.
From another module the derived instance's context must still be dischargeable,
so the via type has to be exported (or otherwise in scope) there — otherwise
the importer sees the usual `unknown type constructor` error, exactly as if it
had named the private type itself.

Via conversions only handle the class parameter at the top level. A method that
uses the parameter under a type constructor (`Parser a`, `[a]`, `f a`) cannot be
coerced and is rejected.

## Strategy Resolution

When no strategy is specified, the first of these that applies is used:

1. If a stock backend exists for the class, use stock.
2. If the type is a `newtype` and GND applies to the class, use newtype.
3. Otherwise, use anyclass. Methods with a class default use it; methods without
   one become `error` bodies, so the instance is complete and only *calling*
   such a method fails.

```moggi
class AllDefault a where
  m :: a -> Int
  m _ = 0

data Foo = Foo
  deriving (AllDefault)          -- anyclass: `m` comes from the class default

data Bar = Bar
  deriving anyclass (AllDefault) -- same, written explicitly
```

Explicit strategies are always available where the class supports them:

```moggi
data Bar = Bar
  deriving stock (Eq)
  deriving newtype (Num)     -- only on newtype
  deriving anyclass (C)

newtype Baz = Baz Int
  deriving via (Sum Int) Num
```

`Generic` refuses newtype deriving (it requires the structural representation);
`via` works for any class with a usable instance.

## Standalone Deriving

Derive instances outside the data declaration. Inline deriving clauses
(`deriving (Eq, Show)`, `deriving newtype Eq`) and standalone declarations
(`deriving instance ...`) are distinguished by the `instance` keyword.

```moggi
data Pair a b = Pair a b

deriving instance (Eq a, Eq b) => Eq (Pair a b)
deriving instance (Show a, Show b) => Show (Pair a b)
```

Each of the four strategies is accepted in standalone form:

```moggi
deriving stock instance Eq a => Eq (T a)
deriving newtype instance Num Age
deriving anyclass instance C Foo
deriving via (Pair a) instance Eq (WrappedPair a)
```

Context rules:

- Stock: the constraints you write are used as written.
- Newtype: a polymorphic representation contributes its implicit constraint
  (e.g. `Eq a`), while a concrete representation contributes none.
- Anyclass: no constraints are added; a method with no default becomes an
  `error` body.
- Via: the implicit `C V` context is merged with any constraints you wrote.

## Class Default Methods

A class may provide default implementations; instances that omit a method fall
back to the default.

```moggi
class Describe a where
  describe :: a -> String
  describe _ = "something"
```

Both the signature and the default body are parsed and registered. `stock`,
`newtype`, and `via` generate all methods explicitly. Hand-written instances
and `anyclass` fall back to defaults, and an `anyclass` method with no default
becomes an `error` body.

A default body is re-checked at each instance site with the class parameter
substituted, so it may use a constraint that is not part of the method's
declared type:

```moggi
class Tag a where
  tag :: a -> String
  tag x = show x        -- needs `Show a`, which `tag :: a -> String` omits

data Suit = Hearts | Spades
  deriving (Show)

instance Tag Suit where  -- ok: `Show Suit` is solved at this instance site

data Rank = Ace | King
  deriving (Show)
  deriving anyclass (Tag)  -- likewise `Show Rank`
```

This is how a library can offer Generic-backed or constructor-less defaults
(`tag x = genericTag x`) without needing a separate default signature.

## Multiple Classes

You can derive multiple classes in a single clause:

```moggi
data Foo = Foo
  deriving (Eq, Ord, Show, Generic)
```

Each class is resolved independently, and an explicit strategy may be combined
with a via type:

```moggi
newtype MyInt = MyInt Int
  deriving via (Sum Int) (Num, Semigroup, Monoid)
```

## Restrictions

- `Generic` cannot use newtype deriving (requires structural representation).
- `newtype` deriving requires a `newtype` declaration.
- `newtype` deriving requires the class parameter to appear as a bare type
  variable, except for `Functor`/`Foldable`/`Traversable`.
- `via` deriving requires the target and via type to share a representation
  after unwrapping newtype chains.
- `via` deriving cannot coerce a class parameter that appears under a type
  constructor.
- `deriving (C)` infers a strategy: stock, then newtype on a `newtype`, then
  anyclass. Write `deriving stock (C)`, `deriving newtype (C)`,
  `deriving anyclass (C)`, or `deriving via (V) C` to be explicit.
- Standalone deriving requires the target type to be in scope.
