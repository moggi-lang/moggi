<?php declare(strict_types=1);

// Regression: inline param substitution must not remapping into call-site
// arguments. Shared local names (callee param `xs` vs arg `listTail#(xs)`)
// previously stacked substitutions via mapOperandTree until the depth cap
// (~65 nested listTail#), breaking optimized JVM/DotNet list peels.
return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"peel","params":["xs"],"type":{"tag":"type_arrow","from":{"tag":"type_app","con":{"tag":"type_con","name":"List"},"args":[{"tag":"type_con","name":"Int"}]},"to":{"tag":"type_app","con":{"tag":"type_con","name":"List"},"args":[{"tag":"type_con","name":"Int"}]}},"body":{"tag":"block","items":[{"tag":"ret","value":{"tag":"intrinsic","name":"listTail#","args":[{"tag":"local","name":"xs"}]}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"twice","params":["xs"],"type":{"tag":"type_arrow","from":{"tag":"type_app","con":{"tag":"type_con","name":"List"},"args":[{"tag":"type_con","name":"Int"}]},"to":{"tag":"type_app","con":{"tag":"type_con","name":"List"},"args":[{"tag":"type_con","name":"Int"}]}},"body":{"tag":"block","items":[{"tag":"call","callee":"peel","args":[{"tag":"intrinsic","name":"listTail#","args":[{"tag":"local","name":"xs"}]}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
