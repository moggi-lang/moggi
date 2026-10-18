# The `filepath` Package

This package provides functionality for manipulating FilePath values.

Three modules:

    - System.FilePath.Posix manipulates POSIX/Linux style paths (`/` separator).

    - System.FilePath.Windows manipulates Windows style paths (`\\` or `/`
      separators, drives).

    - System.FilePath dispatches every operation at run time on
      `System.Info.isWindows`, following the machine the program runs on.

This library is inspired by Haskell's [filepath package](https://hackage.haskell.org/package/filepath).
