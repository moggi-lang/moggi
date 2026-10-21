<?php declare(strict_types=1);

// Regression: DCE's liveness collector must descend into an `io_assign_action`
// body. The def below (`t4`, a call that cannot be inlined because `fib` is not
// in the module) is read nowhere in the enclosing block, only inside the box.
// The old hand-written switch skipped `IoAssignAction` and dropped the call,
// leaving the box with `io_run t4` and no definition.
//
// Source-level companion: `tests/backend/runtime/Exec-Io-Box-Liveness.mog`.

return \json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"main","params":[],"body":{"tag":"block","items":[{"tag":"call","callee":"fib","args":[{"tag":"const","value":10}],"dest":4},{"tag":"io_assign_action","dest":5,"body":{"tag":"block","items":[{"tag":"io_run","action":{"tag":"temp","id":4}}]},"result":{"tag":"unit"}},{"tag":"io_run","action":{"tag":"temp","id":5},"dest":6},{"tag":"ret","value":{"tag":"unit"}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
