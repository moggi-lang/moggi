<?php declare(strict_types=1);

return \json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"label","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"call_value","callee":{"tag":"fn","name":"Data.Function::id"},"args":[{"tag":"const_str","value":"tVal"}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
