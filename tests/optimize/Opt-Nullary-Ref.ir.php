<?php declare(strict_types=1);

return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"report","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"const_str","value":"hello"}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"summary","params":[],"type":{"tag":"type_con","name":"String"},"body":{"tag":"block","items":[{"tag":"let","name":"tagged","value":{"tag":"fn","name":"report"}},{"tag":"ret","value":{"tag":"local","name":"tagged"}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
