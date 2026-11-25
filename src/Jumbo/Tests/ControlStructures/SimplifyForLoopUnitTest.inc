<?php

// For loops: allowed.
for ($i = 0; $i < 10; $i++) {
}
for (; $i < 10; $i++) {
}
for (; $a, $b;) {
}

// For loops: reported.
for (; $i < 10;) {
}
for ( ; $i < 10 ; ) {
}
for (;;) {
}
for (; $i < 10;):
endfor;
for (/* start */; $i < 10;) {
}
for (; /* ever */;) {
}
