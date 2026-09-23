<?php declare(strict_types=1);

return json_decode(<<<'JSON'
{"module":{"tag":"module","functions":[{"tag":"function","name":"greeting","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"const_str","value":"a\nb"}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"tag","params":["label"],"type":{"tag":"type_arrow","from":{"tag":"type_con","name":"String"},"to":{"tag":"type_con","name":"String"}},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"intrinsic","name":"stringAppend#","args":[{"tag":"const_str","value":"["},{"tag":"intrinsic","name":"stringAppend#","args":[{"tag":"local","name":"label"},{"tag":"const_str","value":"]"}]}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}}
JSON, true, flags: JSON_THROW_ON_ERROR);
