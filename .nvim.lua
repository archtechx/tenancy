-- The tailwindcss LSP doesn't play nice with testbench due to the recursive
-- `vendor` symlink in `testbench-core/laravel/vendor`, so we nuke its setup method here.
-- This prevents the setup() call in neovim config from starting the client (or doing anything at all).
require('lspconfig').tailwindcss.setup = function () end
