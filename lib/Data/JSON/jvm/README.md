# Jackson Core (JVM JSON host)

Vendored [jackson-core](https://github.com/FasterXML/jackson-core) 2.17.3
(Apache License 2.0). Used only via ordinary `foreign import` from
-- Streaming JSON uses Jackson Core via Data.JSON.Encoding.JVM.

Not included: `jackson-databind`, `ObjectMapper`, POJO mapping, or any
library-owned Java helper class.

The JVM backend demand-merges jars under this directory into `moggi-app.jar`
when emitted bytecode references packages from those jars.
