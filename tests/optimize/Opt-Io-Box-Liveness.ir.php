<?php declare(strict_types=1);

return \json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"main","params":[],"body":{"tag":"block","items":[{"tag":"call","callee":"fib","args":[{"tag":"const","value":10}],"dest":4},{"tag":"io_assign_action","dest":5,"body":{"tag":"block","items":[{"tag":"io_run","action":{"tag":"temp","id":4}}]},"result":{"tag":"unit"}},{"tag":"io_run","action":{"tag":"temp","id":5},"dest":6},{"tag":"ret","value":{"tag":"unit"}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
