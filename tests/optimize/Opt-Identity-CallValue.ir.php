<?php declare(strict_types=1);

// call_value @id(x) / @Module::id(x) must fold to x (Generic field-label wrappers).
return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"label","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"expr_call_value","callee":{"tag":"fn","name":"Data.Function::id"},"args":[{"tag":"const_str","value":"tVal"}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
