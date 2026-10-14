namespace Acme.Hello;

/// <summary>
/// Fixture third-party class for `moggi bind` Phase 3: a static method, an
/// instance method, a constructor, a constant, a static property, a nominal
/// factory in both argument and result position, and the exact case the
/// sidecar exists for — an instance method with an optional parameter, whose
/// declaration passes the required prefix while the sidecar carries the full
/// IL list and the optional tail the emitter pads.
/// </summary>
public class Greeter
{
    public static readonly string Version = "1.0.0";

    // A C# const has no runtime field to load, so the binder skips it with
    // its reason; the hand-written overlay would name the value instead.
    public const int VersionMajor = 1;

    public static string Tag => "hello";

    private readonly string prefix;

    public Greeter(string prefix)
    {
        this.prefix = prefix;
    }

    public static string Greet(string name)
    {
        return "Hello, " + name + "!";
    }

    // Overload of Greet: one Moggi name cannot spell two members, so the
    // binder keeps the first and reports this one as skipped.
    public static string Greet(string name, int times)
    {
        return Greet(name) + " x" + times;
    }

    // `byte` has no Moggi counterpart, so this member is skipped with its
    // reason rather than bound from a guessed type.
    public static string GreetLevel(string name, byte level)
    {
        return Greet(name) + " #" + level;
    }

    public string GreetWith(string name, int times)
    {
        var greeting = this.prefix + ", " + name + "!";
        var out_ = "";
        for (var i = 0; i < times; ++i)
        {
            out_ += greeting;
        }
        return out_;
    }

    public string GreetWithOptional(string name, int times = 2)
    {
        return GreetWith(name, times);
    }

    public static Greeter Create(string prefix)
    {
        return new Greeter(prefix);
    }

    public static string Describe(Greeter greeter)
    {
        return "greeter(" + greeter.prefix + ")";
    }
}
