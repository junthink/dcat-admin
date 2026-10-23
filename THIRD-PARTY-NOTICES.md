# Third-party notices

The Dcat Admin PHP source and this fork's original changes remain under the root MIT license. Preserve the original copyright notices for Jiang qinghua, Jens Segers and the other upstream contributors.

Bundled third-party frontend software retains its own license. The MIT declaration for the PHP library does not relicense these files.

| Component | License / location |
| --- | --- |
| TinyMCE 8 | GPL version 2 or later, or the applicable Tiny commercial agreement. See `resources/dist/dcat/plugins/tinymce/license.md` and `notices.txt`. |
| DOMPurify | Apache-2.0 OR MPL-2.0; copies in `resources/dist/licenses/dompurify/`. |
| jQuery, Bootstrap, Popper, Perfect Scrollbar, Moment, Moment Timezone, CodeMirror, Marked, Underscore, Raphael, KaTeX and SweetAlert2 | MIT; available upstream license texts are preserved under `resources/dist/licenses/` and/or library headers. |
| Editor.md, Toastr, AdminLTE and other existing plugins | Preserve the license files and copyright headers shipped with each plugin. |

TinyMCE is configured with `license_key: 'gpl'`. Its unminified source, license and notices are included. The build script replaces its embedded DOMPurify with the security version in `package-lock.json` in both the core and Silver theme, records a modification notice, and rebuilds the corresponding minified files. Do not overwrite this patch by manually copying a TinyMCE release into the distribution.

When redistributing or integrating the bundled TinyMCE, comply with the applicable GPL terms, including source availability and notices, or obtain an appropriate commercial license. Setting a commercial key alone does not purchase a commercial license. PHP namespace compatibility does not alter these licensing obligations.

Build tooling is a development dependency and is not loaded by installed PHP applications. `package-lock.json`, `scripts/build-assets.mjs` and the distributed dependency version manifest record how to reproduce the frontend build. Legacy AMD/Gulp Editor.md build entry points have been removed; use this repository's build script.
