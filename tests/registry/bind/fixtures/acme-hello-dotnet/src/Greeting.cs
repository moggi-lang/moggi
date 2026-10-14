namespace Acme.Hello;

/// <summary>
/// Fixture value type for `moggi bind` Phase 3: proves the generated sidecar
/// records `valueType: true` for a struct, the way it records `false` for
/// <see cref="Greeter"/>.
/// </summary>
public struct Greeting
{
    public static string Format(string text)
    {
        return "[" + text + "]";
    }
}
