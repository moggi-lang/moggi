<?php declare(strict_types=1);

return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"double","params":["x"],"type":{"tag":"type_arrow","from":{"tag":"type_con","name":"Int"},"to":{"tag":"type_con","name":"Int"}},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"intrinsic","name":"intAdd#","args":[{"tag":"local","name":"x"},{"tag":"local","name":"x"}]}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"quad","params":["x"],"type":{"tag":"type_arrow","from":{"tag":"type_con","name":"Int"},"to":{"tag":"type_con","name":"Int"}},"body":{"tag":"block","items":[{"tag":"call","callee":"double","args":[{"tag":"local","name":"x"}],"dest":0},{"tag":"call","callee":"double","args":[{"tag":"temp","id":0}],"dest":1},{"tag":"ret","value":{"tag":"temp","id":1}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
