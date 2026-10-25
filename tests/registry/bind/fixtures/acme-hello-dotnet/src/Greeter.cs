namespace Acme.Hello;

/**
 * Fixture third-party class for `moggi bind` Phase 3. Covers static and
 * instance methods, a constructor, constant, static property, and nominal
 * factories in argument and result positions.
 *
 * Also covers the sidecar's key case: an instance method with an optional
 * parameter. The declaration exposes the required prefix; the sidecar
 * carries the full IL parameter list, including the optional tail that
 * the emitter pads.
 */
public class Greeter
{
    public static readonly string Version = "1.0.0";

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

    public static string Greet(string name, int times)
    {
        return Greet(name) + " x" + times;
    }

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
