# Vendored third-party assets

## snapdom.min.js — SnapDOM v2.24.1, MIT (© ZumerLab)

https://github.com/zumerlab/snapdom

Captures a DOM subtree to an image inside the browser. Used by the Template
Board to generate template thumbnails locally, so template markup is never
sent to an outside screenshot service.

Vendored rather than loaded from a CDN on purpose: the point of capturing
locally is that nothing about the site leaves it, which a third-party script
tag would undo.

To update: replace this file from
https://cdn.jsdelivr.net/npm/@zumer/snapdom@<version>/dist/snapdom.min.js
and re-check the thumbnails still render (see modules/etch/class-karo-kit-etch-board.php).
