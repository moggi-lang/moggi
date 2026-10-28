<?php declare(strict_types=1);

return \json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"age","params":["u"],"type":null,"body":{"tag":"block","items":[{"tag":"call","callee":"__field1","args":[{"tag":"local","name":"u"}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"ageOf","params":[],"type":null,"body":{"tag":"block","items":[{"tag":"call","callee":"User","args":[{"tag":"const_str","value":"ann"},{"tag":"const","value":30}],"dest":1},{"tag":"call","callee":"age","args":[{"tag":"temp","id":1}],"dest":2},{"tag":"ret","value":{"tag":"temp","id":2}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
