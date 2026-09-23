<?php declare(strict_types=1);

// Apply collapsing: `partial`/`call_value` chains whose callee arity is known
// must become one direct `expr_call`.
//
//   chain   partial add4(1) ; t(2) ; t(3) ; t(n)  ->  expr_call add4(1, 2, 3, n)
//   exact   @add4(10, 20, 30, 40)                 ->  expr_call add4(10, 20, 30, 40)
//
// Each fusion consumes one `call_value` and turns the remaining chain into an
// `expr_partial`, so completing a 4-argument application requires the fold to
// accept that `expr_partial` spelling on the next pass. Leaving it as
// `__partial` cells plus runtime `__apply` is the regression this pins.
//
// Guard cases that must *not* be rewritten are pinned here too: an
// over-application (`|args| > arity`) and an indirect callee with no known
// arity.
//
// `add4` is deliberately too large to inline (5 statements), so the shapes
// survive to the fold exactly as inlining would leave them.
return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"add4","params":["a","b","c","d"],"type":null,"body":{"tag":"block","items":[{"tag":"binop","op":"intAdd#","left":{"tag":"local","name":"a"},"right":{"tag":"local","name":"b"},"dest":0},{"tag":"binop","op":"intAdd#","left":{"tag":"temp","id":0},"right":{"tag":"local","name":"c"},"dest":1},{"tag":"binop","op":"intAdd#","left":{"tag":"temp","id":1},"right":{"tag":"local","name":"d"},"dest":2},{"tag":"binop","op":"intAdd#","left":{"tag":"temp","id":2},"right":{"tag":"const","value":1},"dest":3},{"tag":"ret","value":{"tag":"temp","id":3}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"chain","params":["n"],"type":null,"body":{"tag":"block","items":[{"tag":"assign","dest":0,"value":{"tag":"partial","fn":"add4","arity":4,"args":[{"tag":"const","value":1}]}},{"tag":"call_value","callee":{"tag":"temp","id":0},"args":[{"tag":"const","value":2}],"dest":1},{"tag":"call_value","callee":{"tag":"temp","id":1},"args":[{"tag":"const","value":3}],"dest":2},{"tag":"call_value","callee":{"tag":"temp","id":2},"args":[{"tag":"local","name":"n"}],"dest":3},{"tag":"ret","value":{"tag":"temp","id":3}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"exact","params":[],"type":null,"body":{"tag":"block","items":[{"tag":"call_value","callee":{"tag":"fn","name":"add4"},"args":[{"tag":"const","value":10},{"tag":"const","value":20},{"tag":"const","value":30},{"tag":"const","value":40}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"over","params":["n"],"type":null,"body":{"tag":"block","items":[{"tag":"call_value","callee":{"tag":"fn","name":"add4"},"args":[{"tag":"const","value":1},{"tag":"const","value":2},{"tag":"const","value":3},{"tag":"const","value":4},{"tag":"local","name":"n"}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false},{"tag":"function","name":"unknown","params":["f","n"],"type":null,"body":{"tag":"block","items":[{"tag":"call_value","callee":{"tag":"local","name":"f"},"args":[{"tag":"local","name":"n"}],"dest":0},{"tag":"ret","value":{"tag":"temp","id":0}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
