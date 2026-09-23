<?php declare(strict_types=1);

return json_decode(<<<'JSON'
{"module":{"tag":"module","functions":[{"tag":"function","name":"twice","params":["x"],"type":{"tag":"type_arrow","from":{"tag":"type_con","name":"Int"},"to":{"tag":"type_con","name":"Int"}},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"intrinsic","name":"intAdd#","args":[{"tag":"local","name":"x"},{"tag":"local","name":"x"}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}}
JSON, true, flags: JSON_THROW_ON_ERROR);
