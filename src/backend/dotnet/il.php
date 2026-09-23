<?php declare(strict_types=1);

namespace Moggi\Backend\DotNet\Il;

/** Append-only buffer for ILASM (ECMA-335) source fragments. */
final class IlText
{
    private string $buf = '';

    public function line(string $s = ''): self
    {
        $this->buf .= $s . "\n";

        return $this;
    }

    public function lines(string ...$lines): self
    {
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /** Indent helper for nested IL. */
    public function indented(string $s, int $spaces = 2): self
    {
        $pad = \str_repeat(' ', $spaces);
        foreach (\explode("\n", $s) as $line) {
            $this->line($line === '' ? '' : $pad . $line);
        }

        return $this;
    }

    public function raw(string $s): self
    {
        $this->buf .= $s;

        return $this;
    }

    public function toString(): string
    {
        return $this->buf;
    }

    public function clear(): void
    {
        $this->buf = '';
    }
}

/** Escape a string for ILASM `ldstr "..."` / custom attributes. */
function ilString(string $s): string
{
    // Prefer raw UTF-8 in the .il source (Sdk.IL accepts UTF-8 files). Only
    // escape quotes, backslashes, and C0 controls — \uXXXX is unreliable across
    // ilasm flavours for supplementary-plane text.
    $out = '';
    $len = \strlen($s);
    $i = 0;
    while ($i < $len) {
        $o = \ord($s[$i]);
        if ($o < 0x80) {
            $cp = $o;
            $n = 1;
        } elseif ($o < 0xE0 && $i + 1 < $len) {
            $cp = (($o & 0x1F) << 6) | (\ord($s[$i + 1]) & 0x3F);
            $n = 2;
        } elseif ($o < 0xF0 && $i + 2 < $len) {
            $cp = (($o & 0x0F) << 12) | ((\ord($s[$i + 1]) & 0x3F) << 6) | (\ord($s[$i + 2]) & 0x3F);
            $n = 3;
        } elseif ($o < 0xF8 && $i + 3 < $len) {
            $cp = (($o & 0x07) << 18)
                | ((\ord($s[$i + 1]) & 0x3F) << 12)
                | ((\ord($s[$i + 2]) & 0x3F) << 6)
                | (\ord($s[$i + 3]) & 0x3F);
            $n = 4;
        } else {
            $cp = 0xFFFD;
            $n = 1;
        }

        if ($cp === 0x5C) {
            $out .= '\\\\';
        } elseif ($cp === 0x22) {
            $out .= '\\"';
        } elseif ($cp === 0x0A) {
            $out .= '\\n';
        } elseif ($cp === 0x0D) {
            $out .= '\\r';
        } elseif ($cp === 0x09) {
            $out .= '\\t';
        } elseif ($cp < 0x20) {
            $out .= '\\u' . \sprintf('%04x', $cp);
        } else {
            $out .= \substr($s, $i, $n);
        }
        $i += $n;
    }

    return '"' . $out . '"';
}

/**
 * Standard .assembly extern preamble for net8.0 framework-dependent apps.
 */
function assemblyExternPreamble(): string
{
    return <<<'IL'
.assembly extern System.Runtime
{
  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)
  .ver 8:0:0:0
}
.assembly extern System.Console
{
  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)
  .ver 8:0:0:0
}
.assembly extern System.Runtime.Numerics
{
  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)
  .ver 8:0:0:0
}
.assembly extern System.Collections
{
  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)
  .ver 8:0:0:0
}
.assembly extern System.Linq
{
  .publickeytoken = (B0 3F 5F 7F 11 D5 0A 3A)
  .ver 8:0:0:0
}

IL;
}

/** Assembly names already emitted by assemblyExternPreamble(). */
function preambleAssemblyNames(): array
{
    return ['System.Runtime', 'System.Console', 'System.Runtime.Numerics', 'System.Collections', 'System.Linq'];
}

/**
 * `.assembly extern` refs for assemblies the generated IL actually references
 * beyond the fixed preamble. Demand-driven and domain-agnostic: the list comes
 * from `[Assembly]Type` qualifiers in the emitted IL, which libs write in their
 * own foreign declarations.
 *
 * A public key token is not needed: framework-dependent apps bind by simple
 * name against the shared framework and the app's deps.
 *
 * @param list<string> $assemblies
 */
function assemblyExternsFor(array $assemblies): string
{
    $out = '';
    foreach ($assemblies as $name) {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
            continue;
        }
        $out .= ".assembly extern {$name}\n{\n  .ver 8:0:0:0\n}\n";
    }

    return $out === '' ? '' : $out . "\n";
}
