<?php declare(strict_types=1);

return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"dead","params":["x"],"type":{"tag":"type_arrow","from":{"tag":"type_con","name":"Int"},"to":{"tag":"type_con","name":"Int"}},"body":{"tag":"block","items":[{"tag":"let","name":"y","value":{"tag":"const","value":42}},{"tag":"ret","value":{"tag":"intrinsic","name":"intAdd#","args":[{"tag":"local","name":"x"},{"tag":"const","value":1}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
