/* A minimal extension used by the extension-install tests: one function whose
 * existence the compiler can only know about when the extension is loaded. */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"

PHP_FUNCTION(moggi_probe_ping)
{
    zend_string *input;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(input)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_STR(strpprintf(0, "pong:%s", ZSTR_VAL(input)));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_moggi_probe_ping, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry moggi_probe_functions[] = {
    PHP_FE(moggi_probe_ping, arginfo_moggi_probe_ping)
    PHP_FE_END
};

zend_module_entry moggi_probe_module_entry = {
    STANDARD_MODULE_HEADER,
    "moggi_probe",
    moggi_probe_functions,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    "0.1.0",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_MOGGI_PROBE
ZEND_GET_MODULE(moggi_probe)
#endif
