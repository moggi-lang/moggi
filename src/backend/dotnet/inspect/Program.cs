/*
 * CLR reflection inspector: resolved assemblies in, JSON out.
 *
 * Usage: inspect.dll --refs <dll-or-dir>... --type <FullName>
 *        inspect.dll --refs <dll-or-dir>... --list <assembly.dll>
 * Prints one JSON document describing the named type (methods with their IL
 * parameter lists, constructors, fields, properties), or {"found":false}.
 * --list prints every exported type of one assembly with its kind flags, for
 * the binding generator's enumeration pass. The compiler compares the
 * signature it is about to emit against this, the way `javap` answers for
 * the JVM backend. Member lookup uses `+` for nested types on the way in;
 * types render the way the emitter spells them, including `/` for nested
 * types (e.g. JsonElement/ObjectEnumerator).
 *
 * Built on demand by the compiler from this source with the SDK's own
 * Roslyn (no project, no restore, no network), then cached. It only reads
 * metadata — nothing is executed.
 */

#nullable disable

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text.Json;

static class Inspector
{
    static string RenderType(Type t)
    {
        if (t.IsByRef) return RenderType(t.GetElementType()) + "&";
        if (t.IsPointer) return RenderType(t.GetElementType()) + "*";
        if (t.IsArray)
        {
            var elem = RenderType(t.GetElementType());
            return t.GetArrayRank() == 1 ? elem + "[]" : elem + "[...]";
        }
        if (t.IsGenericParameter) return "!" + t.GenericParameterPosition;
        if (t == typeof(void)) return "void";
        if (t == typeof(string)) return "string";
        if (t == typeof(object)) return "object";
        if (t == typeof(bool)) return "bool";
        if (t == typeof(char)) return "char";
        if (t == typeof(sbyte)) return "int8";
        if (t == typeof(short)) return "int16";
        if (t == typeof(int)) return "int32";
        if (t == typeof(long)) return "int64";
        if (t == typeof(byte)) return "uint8";
        if (t == typeof(ushort)) return "uint16";
        if (t == typeof(uint)) return "uint32";
        if (t == typeof(ulong)) return "uint64";
        if (t == typeof(float)) return "float32";
        if (t == typeof(double)) return "float64";
        var kind = t.IsValueType ? "valuetype " : "class ";
        var asm = t.Assembly.GetName().Name;
        var name = (t.FullName ?? t.Name).Replace('+', '/');
        if (!t.IsGenericType) return kind + "[" + asm + "]" + name;
        var tick = name.IndexOf('`');
        var head = tick < 0 ? name : name.Substring(0, tick);
        var args = string.Join(", ", t.GetGenericArguments().Select(RenderType));
        return kind + "[" + asm + "]" + head + "`" + t.GetGenericArguments().Length + "<" + args + ">";
    }

    static Dictionary<string, object> MethodEntry(MethodBase m)
    {
        var ps = m.GetParameters();
        int optional = 0;
        for (int i = ps.Length - 1; i >= 0 && ps[i].IsOptional; i--) optional++;
        return new Dictionary<string, object>
        {
            ["params"] = ps.Select(p => RenderType(p.ParameterType)).ToArray(),
            ["ret"] = m is MethodInfo mi ? RenderType(mi.ReturnType) : "void",
            ["static"] = m.IsStatic,
            ["public"] = m.IsPublic,
            ["optional"] = optional,
            ["declared"] = m.DeclaringType?.FullName ?? "",
        };
    }

    static int ListAssembly(string path)
    {
        Assembly a;
        try { a = Assembly.LoadFrom(path); }
        catch (Exception e)
        {
            Console.Out.Write(JsonSerializer.Serialize(new Dictionary<string, object>
            {
                ["error"] = "cannot load " + path + ": " + e.GetType().Name,
            }));
            return 1;
        }
        Type[] types;
        try { types = a.GetExportedTypes(); }
        catch (Exception e)
        {
            Console.Out.Write(JsonSerializer.Serialize(new Dictionary<string, object>
            {
                ["error"] = "cannot enumerate " + path + ": " + e.GetType().Name,
            }));
            return 1;
        }
        var out_types = types
            .Where(t => t.FullName != null)
            .OrderBy(t => t.FullName, StringComparer.Ordinal)
            .Select(t => (object)new Dictionary<string, object>
            {
                ["name"] = t.FullName,
                ["valueType"] = t.IsValueType,
                ["interface"] = t.IsInterface,
                ["enum"] = t.IsEnum,
                ["abstract"] = t.IsAbstract,
            }).ToArray();
        Console.Out.Write(JsonSerializer.Serialize(new Dictionary<string, object>
        {
            ["assembly"] = a.GetName().Name ?? "",
            ["types"] = out_types,
        }));
        return 0;
    }

    static int Main(string[] args)
    {
        var refs = new List<string>();
        string typeName = null;
        string listPath = null;
        for (int i = 0; i < args.Length; i++)
        {
            if (args[i] == "--refs")
            {
                while (i + 1 < args.Length && !args[i + 1].StartsWith("--")) refs.Add(args[++i]);
            }
            else if (args[i] == "--type" && i + 1 < args.Length) { typeName = args[++i]; }
            else if (args[i] == "--list" && i + 1 < args.Length) { listPath = args[++i]; }
        }
        if (listPath != null) return ListAssembly(listPath);
        if (typeName == null)
        {
            Console.Out.Write("{\"error\":\"usage: inspect --refs <dll-or-dir>... --type <FullName>\"}");
            return 2;
        }
        var files = new List<string>();
        foreach (var r in refs)
        {
            if (Directory.Exists(r)) files.AddRange(Directory.GetFiles(r, "*.dll"));
            else if (File.Exists(r)) files.Add(r);
        }
        var loaded = new List<Assembly>();
        foreach (var f in files.Distinct())
        {
            try { loaded.Add(Assembly.LoadFrom(f)); }
            catch { }
        }
        var lookup = typeName.Replace('/', '+');
        Type found = null;
        foreach (var a in loaded)
        {
            try { found = a.GetType(lookup, false); } catch { }
            if (found != null) break;
        }
        if (found == null)
        {
            try { found = Type.GetType(lookup, false); } catch { }
        }
        if (found == null)
        {
            Console.Out.Write(JsonSerializer.Serialize(new Dictionary<string, object>
            {
                ["found"] = false,
                ["type"] = typeName,
            }));
            return 0;
        }
        var methods = new Dictionary<string, List<Dictionary<string, object>>>();
        for (var t = found; t != null; t = t.BaseType)
        {
            foreach (var m in t.GetMethods(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static | BindingFlags.DeclaredOnly))
            {
                if (m.IsSpecialName && !m.Name.StartsWith("get_") && !m.Name.StartsWith("set_") && !m.Name.StartsWith("op_")) continue;
                if (!methods.TryGetValue(m.Name, out var list)) { list = new List<Dictionary<string, object>>(); methods[m.Name] = list; }
                list.Add(MethodEntry(m));
            }
            if (t == typeof(object)) break;
        }
        var ctors = found.GetConstructors(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance)
            .Select(c => (object)new Dictionary<string, object>
            {
                ["params"] = c.GetParameters().Select(p => RenderType(p.ParameterType)).ToArray(),
                ["public"] = c.IsPublic,
            }).ToArray();
        var fields = new Dictionary<string, object>();
        foreach (var f in found.GetFields(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static))
        {
            fields[f.Name] = new Dictionary<string, object>
            {
                ["type"] = RenderType(f.FieldType),
                ["static"] = f.IsStatic,
                ["public"] = f.IsPublic,
                ["literal"] = f.IsLiteral,
                ["initOnly"] = f.IsInitOnly,
            };
        }
        var props = new Dictionary<string, object>();
        foreach (var p in found.GetProperties(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static))
        {
            var getter = p.GetGetMethod(true);
            props[p.Name] = new Dictionary<string, object>
            {
                ["type"] = RenderType(p.PropertyType),
                ["static"] = getter != null && getter.IsStatic,
                ["public"] = getter != null && getter.IsPublic,
                ["hasGetter"] = getter != null,
            };
        }

        var forwardedBy = new List<string>();
        foreach (var a in loaded)
        {
            Type[] forwarded;
            try { forwarded = a.GetForwardedTypes(); } catch { continue; }
            foreach (var f in forwarded)
            {
                if (f == found || f.FullName == found.FullName) { forwardedBy.Add(a.GetName().Name ?? ""); break; }
            }
        }
        Console.Out.Write(JsonSerializer.Serialize(new Dictionary<string, object>
        {
            ["found"] = true,
            ["type"] = typeName,
            ["assembly"] = found.Assembly.GetName().Name ?? "",
            ["forwardedBy"] = forwardedBy.Distinct().ToArray(),
            ["valueType"] = found.IsValueType,
            ["methods"] = methods,
            ["ctors"] = ctors,
            ["fields"] = fields,
            ["properties"] = props,
        }));
        return 0;
    }
}
