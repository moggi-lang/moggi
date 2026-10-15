<?php declare(strict_types=1);

// `call_value @id(x)` does not fold by spelling: an identity call is only folded where the
// provider's declaration can be read (@see Moggi\Optimize\Partial\returnedIdentityArg), and this
// module has no provider for `Data.Function::id`, so the call stands. When the library can declare
// that a function is the identity (P4 stage 4), this expectation folds again.
return \json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"label","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"expr_call_value","callee":{"tag":"fn","name":"Data.Function::id"},"args":[{"tag":"const_str","value":"tVal"}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
