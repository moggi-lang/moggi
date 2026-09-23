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

```json
{
  "assemblies": {
    "Some.Assembly": {
      "types": {
        "Some.Assembly.Namespace.Type": {},
        "Some.Assembly.Namespace.ValueType": { "valueType": true }
      },
      "signatures": {
        "someForeignBinding": ["int64", "class [System.Runtime]System.IFormatProvider"]
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
