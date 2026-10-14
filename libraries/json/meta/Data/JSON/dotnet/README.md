# .NET host metadata

Library-owned CLR details for the .NET backend. A `dotnet/` directory next to a
module's `.mog` sources may hold `*.json` files declaring what an ordinary
Moggi `foreign dotnet` declaration cannot express. The backend discovers every
such directory under the stdlib root generically — there is no per-library
special case in the compiler, just as `lib/<module>/jvm/` is discovered for
vendored jars.

The FFI declaration itself stays a plain host name:

```mog
foreign dotnet type JsonValueKind "System.Text.Json.JsonValueKind"
```

## Format

`"format": 1`. A file claiming a newer format refuses the compile — update
moggi — so the schema can grow without old compilers misreading it.

```json
{
  "format": 1,
  "assemblies": {
    "Some.Assembly": {
      "version": "9.0.0",
      "digest": "sha256:<of the resolved Some.Assembly.dll, when there is one>",
      "generated-by": "moggi-bindgen 0.1 (System.Text.Json.*)",
      "types": {
        "Some.Assembly.Namespace.Type": {},
        "Some.Assembly.Namespace.ValueType": { "valueType": true }
      },
      "signatures": {
        "plainImport": {
          "optionalTail": 0,
          "params": ["string"]
        },
        "withOptionals": {
          "optionalTail": 2,
          "params": [
            "string",
            "valuetype [System.Runtime]System.Nullable`1<valuetype [System.Text.Json]System.Text.Json.Nodes.JsonNodeOptions>",
            "valuetype [System.Text.Json]System.Text.Json.JsonDocumentOptions"
          ]
        },
        "interfaceBlessed": {
          "optionalTail": 0,
          "params": [
            "string",
            {
              "emit": "class [System.Runtime]System.IFormatProvider",
              "from": "class [System.Runtime]System.Globalization.CultureInfo"
            }
          ]
        }
      }
    }
  }
}
```

- `types` maps a CLR type to the assembly that defines it. The backend scopes
  every TypeRef to that assembly and picks `valuetype` vs `class` from
  `valueType`. A type declared with two different assemblies is a hard error.
- `signatures` gives the exact IL parameter list for one foreign import, keyed
  by the import's Moggi binding name. Use it when the real CLR signature cannot
  be derived from the Moggi type — most commonly C# optional parameters, which
  IL call sites must still pass explicitly.

A sidecar *describes* the declaration; it never overrides it. The declared
parameter list must match the entry's shared prefix typewise, and every
parameter past the declaration must sit inside `optionalTail` — anything else
is a compile error naming both. `from` blesses one position where the call
passes a subtype the declaration names (an interface the host type implements):
the check compares against `from`, the emitter calls with `emit`.

The old spelling — a bare parameter list instead of the object — still loads
but warns once per file, and it names no optional tail, so any extra past the
declaration is refused. Migrate it to the object form.

## Scope

An entry applies only to imports from the same tree it was read from: a
dependency's sidecar cannot change another module's binding. A project file,
under no library root, assembles every root, so its own imports may use any
sidecar. An import with no applicable entry still compiles, with a
once-per-binding warning saying it is not verified against the CLR.

## Provenance

`version`, `digest` and `generated-by` record what the entry was made from. A
`digest` is checked against the resolved `<assembly>.dll` when that file is on
disk, and a mismatch refuses the compile — a stale sidecar must not describe a
new assembly. Framework assemblies never materialize as files in the tree, so
their digests are recorded but not verified.
