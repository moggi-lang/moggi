PHP_ARG_ENABLE([moggi_probe],
  [whether to enable moggi_probe support],
  [AS_HELP_STRING([--enable-moggi_probe], [Enable moggi_probe support])],
  [no])

if test "$PHP_MOGGI_PROBE" != "no"; then
  PHP_NEW_EXTENSION([moggi_probe], [moggi_probe.c], [$ext_shared])
fi
