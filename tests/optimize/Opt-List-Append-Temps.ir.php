<?php declare(strict_types=1);

// Known list spines behind multi-use Temps must still fold through listAppend#
// (copy-prop refuses compound values used more than once).
return json_decode(<<<'JSON'
{"tag":"module","functions":[{"tag":"function","name":"appendKnown","params":[],"type":{"tag":"type_app","con":{"tag":"type_con","name":"List"},"args":[{"tag":"type_con","name":"Int"}]},"body":{"tag":"block","items":[{"tag":"assign","dest":1,"value":{"tag":"intrinsic","name":"listCons#","args":[{"tag":"const","value":1},{"tag":"list_lit","elements":[]}]}},{"tag":"assign","dest":2,"value":{"tag":"intrinsic","name":"listCons#","args":[{"tag":"const","value":2},{"tag":"list_lit","elements":[]}]}},{"tag":"assign","dest":3,"value":{"tag":"intrinsic","name":"listAppend#","args":[{"tag":"temp","id":1},{"tag":"temp","id":2}]}},{"tag":"ret","value":{"tag":"intrinsic","name":"listAppend#","args":[{"tag":"temp","id":1},{"tag":"temp","id":2}]}}]},"export":false,"instanceMethod":false,"entryPoint":false}],"data":[],"instanceEvidence":[],"entryMain":null}
JSON, true, flags: JSON_THROW_ON_ERROR);
