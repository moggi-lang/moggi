package com.acme.hello;

/**
 * Fixture third-party class for `moggi bind` Phase 2: a static method, an
 * instance method, a throwing method (checked exception, so the binding is
 * `IO`), a constructor, a constant, and an interface-typed factory proving a
 * nominal interface maps in both argument and result position.
 */
public class Greeter implements GreeterService {
    public static final String VERSION = "1.0.0";

    private final String prefix;

    public Greeter(String prefix) {
        this.prefix = prefix;
    }

    public static String greet(String name) {
        return "Hello, " + name + "!";
    }

    public String greetWith(String name, int times) {
        String greeting = this.prefix + ", " + name + "!";
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < times; ++i) {
            out.append(greeting);
        }
        return out.toString();
    }

    public static String loadGreeting(String name) throws java.io.IOException {
        throw new java.io.IOException("no greetings file for " + name);
    }

    public static GreeterService service(String prefix) {
        return new Greeter(prefix);
    }

    @Override
    public String serve(String name) {
        return this.prefix + ": " + name;
    }
}
