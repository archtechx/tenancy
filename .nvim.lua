-- The tailwindcss LSP doesn't play nice with testbench due to the recursive
-- `vendor` symlink in `testbench-core/laravel/vendor`, so we disable it here.
vim.lsp.enable('tailwindcss', false)
