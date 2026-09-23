# The REPL

The REPL is the fastest way to try a piece of Moggi: type an expression, get its
value; ask for a type; load a file and poke at it. Nothing is compiled to an
artifact and nothing is left on disk.

```bash
moggi repl
```

```text
Moggi REPL (php). Type :help for commands, :quit to exit.
> 1 + 2
3
> "Hello, " <> "world!"
Hello, world!
> map (\x -> x * 2) [1, 2, 3]
[2,4,6]
> add 1
<function>
```

## What you can type

* **Expressions** are evaluated and printed: `1 + 2`, `show (Circle 3.0)`,
  `map (+ 1) [1, 2, 3]`.
* **Declarations** persist for the rest of the session: `double x = x * 2` and
  `twice :: Int -> Int` are as usable as a binding you type inside an
  expression. `:browse` lists what you have defined.
* **Imports** too: `import Data.List as L`, then `L.unzip [(1,"a")]`. A name that
  is not in scope is a normal compile error here, not a special REPL case.
* **Partial application** shows its value: `add 1` is a function, and
  `:type add 1` says `Int -> Int`.

## Commands

Every command starts with `:`. `:help` prints this list.

| Command | What it does |
|---|---|
| `:type expr`, `:t` | the type of an expression or binding |
| `:kind Type`, `:k` | the kind of a type (or of an expression's type) |
| `:info Name`, `:i` | declaration information for a name |
| `:load file`, `:l` | load a module, so its definitions are in scope |
| `:reload`, `:r` | reload the loaded module after editing it |
| `:browse` | list the bindings defined in this session |
| `:show imports` | show the active imports |
| `:module [Name]` | show or set the interactive module name |
| `:history` | command history |
| `:clear` | reset the interactive state |
| `:backend php\|jvm\|dotnet` | switch the evaluation backend |
| `:quit`, `:q` | exit |

```text
> :type putStrLn
putStrLn :: String -> IO ()
> :kind Maybe
Maybe :: Type -> Type
> :load Demo/Shapes.mog
> area (Circle 2.0)
12.566370614359172
```

`:backend` is worth noticing once: the REPL evaluates on the backend you select,
so `:backend jvm` is how you check that a program behaves the same off PHP —
including the host calls in a `foreign jvm` declaration.

## Looking inside the compiler

The same stages the compiler runs are available for a fragment, or for the whole
interactive module:

| Command | Shows |
|---|---|
| `:ast [expr\|decl]` | the parsed tree |
| `:ir [expr\|decl]` | backend-neutral IR |
| `:ir-opt [expr\|decl]` | IR after optimization |
| `:emit [expr\|decl]` | the generated host code for the fragment |
| `:emit-opt [expr\|decl]` | the same, optimized |
| `:dump ast\|typed-ast\|ir\|ir-opt\|emit\|emit-opt` | that stage of the **whole** interactive module |

For a file rather than a session, one flag each does the same and prints to
stdout:

```bash
moggi compile hello.mog --tokens        # the token stream
moggi compile hello.mog --ast           # the parsed tree
moggi compile hello.mog --typed-ast     # after type checking and elaboration
moggi compile hello.mog --ir            # backend-neutral IR
moggi compile hello.mog --opt-ir        # after optimization
```

[pipeline.md](pipeline.md) explains what each stage produces and where it lives,
and [optimizations.md](development/optimizations.md) documents the passes
themselves.

## Next

[stdlib.md](stdlib.md) — find your way around the standard library.
