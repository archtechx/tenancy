#!/usr/bin/env nu

# Utility for exporting static properties used for configuration
def main []: nothing -> string {
    "See --help for subcommands"
}

# The current number of config static properties in the codebase
def "main count" [...paths: string]: nothing -> int {
    props ...$paths | length
}

# Available static properties, grouped by file, rendered as a table
def "main table" [...paths: string]: nothing -> string {
    props ...$paths | table --theme rounded --expand
}

# Plain text version of available static properties
def "main plain" [...paths: string]: nothing -> string {
    props ...$paths
        | each { $"// File: ($in.file)\n($in.props | str join "\n\n")"}
        | str join "\n//------------------------------------------------------------\n\n"
}

# Expressive Code formatting of available static properties, used in docs
def "main docs" [...paths: string]: nothing -> string {
    (("{/* GENERATED_BEGIN */}\n" + (props ...$paths
        | each { update props { each { if ($in | str ends-with "= [") {
            $"($in)/* ... */];"
        } else { $in }}}}
        | each { $"```php /public static .*$/\n// File: ($in.file)\n($in.props | str join "\n\n")\n```"}
        | str join "\n\n"))
    + "\n{/* GENERATED_END */}")
}

def props [...paths: string]: nothing -> table<file: string, props: list<string>> {
    ls ...(if ($paths | length) > 0 {
        ($paths | each {|path|
            if ($path | str contains "*") {
                # already a glob expr
                $path | into glob
            } else if ($path | str ends-with ".php") {
                # src/Foo/Bar.php
                $path
            } else {
                # just 'src/Foo' passed
                $"($path)/**/*.php" | into glob
            }
        })
    } else {
        [("src/**/*.php" | into glob)]
    })
        | each { { name: $in.name, content: (open $in.name) } }
        | find -nr 'public static (?!.*function)'
        | par-each {|file|
            let lines = $file.content | lines
            mut docblock_start = 0
            mut docblock_end = 0
            mut props = []
            for line in ($lines | enumerate) {
                if ($line.item | str contains "/**") {
                    $docblock_start = $line.index
                }

                if ($line.item | str contains "@internal") {
                    # Docblocks with @internal are ignored
                    $docblock_start = 0
                    $docblock_end = 0
                }

                if ($line.item | str contains "*/") {
                    $docblock_end = $line.index
                }

                if (
                    (
                        ( # Valid (non-internal) docblock
                            $docblock_start != 0 and
                            $docblock_end != 0 and
                            $docblock_end == ($line.index - 1)
                        ) or
                        ( # No docblock
                            $line.index != 0 and
                            (($lines | get ($line.index - 1)) | str index-of "*/") == -1
                        )
                    ) and
                    ($line.item | str trim | str index-of "public static") == 0 and
                    ($line.item | str trim | str index-of "public static function") == -1
                ) {
                    if ($docblock_start == 0) or ($docblock_end == 0) or ($docblock_end != ($line.index - 1)) {
                        $docblock_start = $line.index
                        $docblock_end = $line.index
                    }
                    $props = $props | append ($lines | slice $docblock_start..$line.index | each { str trim } | str join "\n")
                    $docblock_start = 0
                    $docblock_end = 0
                }
            }

            {file: $file.name, props: $props}
          }
        | where ($it.props | length) > 0
}
