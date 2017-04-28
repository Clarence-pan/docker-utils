<?php

ini_set('display_errors', 'on');
error_reporting(E_ALL);

function main($argv){
    if (count($argv) <= 1){
        print_help();
        return 1;
    }

    $file = $argv[1];
    if (!file_exists($file)){
        print_help();
        return 1;
    }

    echo "Parsing dockerfile...\n";
    $commands = parse_dockerfile($file);

    echo "Merging RUN...\n";
    $commands = merge_run_commands($commands);

    echo "Regenerate dockerfile...\n";
    $regeneratedFileContent = implode("\n", array_map(function($cmd){
        switch ($cmd['type']) {
            case 'space':
                return '';
            case 'comment':
                return $cmd['text'];
            default:
                return $cmd['type'] . ' ' . $cmd['text'];
        }

    }, $commands)) . "\n";

    echo "Writing dockerfile...\n";
    file_put_contents($file, $regeneratedFileContent);

    echo "Done.\n";
    return 0;
}

function parse_dockerfile($file){
    $lines = file($file);

    $commands = [];

    $toBeContinuedCmd = null;

    foreach($lines as $i => $line){
        $line = rtrim($line);

        if ($toBeContinuedCmd){
            $toBeContinuedCmd['text'] .= "\n" . $line;
            if (empty($line) || $line[strlen($line) - 1] !== "\\"){
                $commands[] = $toBeContinuedCmd;
                $toBeContinuedCmd = null;
            }

            continue;
        }   

        if (preg_match('/^\s*(?<type>[A-Z]+)\s+(?<text>.*)$/', $line, $matches)){
            $cmd = [
                "type" => $matches['type'],
                "text" => $matches['text'],
            ];

            if ($line[strlen($line) - 1] === "\\"){
                $toBeContinuedCmd = $cmd;
            } else {
                $commands[] = $cmd;
            }

            continue;
        }

        if (preg_match('/^\s*#/', $line)){
            $commands[] = [
                "type" => 'comment',
                "text" => $line,
            ];

            continue;
        }

        if (preg_match('/^\s*$/', $line)){
            $commands[] = [
                "type" => "space",
                "text" => $line,
            ];
            continue;
        }

        throw new Exception("Unknown type of line[$i]: " . $line);
    }

    return $commands;
}

function merge_run_commands($originalCommands){
    $resultCommands = [];

    $runCmd = null;

    foreach ($originalCommands as $cmd){
        if ($cmd['type'] !== 'RUN'){
            if ($runCmd){
                if ($cmd['type'] === 'space' || $cmd['type'] === 'comment'){
                    $runCmd['text'] .= " \\\n    " . $cmd['text'];
                } else {
                    $resultCommands[] = $runCmd;
                    $runCmd = null;

                    $resultCommands[] = $cmd;
                }
            } else {
                    $resultCommands[] = $cmd;
            }
        } else { // RUN cmd:
            if ($runCmd){
                $runCmd['text'] .= " \\\n    && " . $cmd['text'];
            } else {
                $runCmd = $cmd;
            }
        }
    }

    return $resultCommands;
}

function print_help(){
    echo <<<HELP
Usage: merge-docker-run-commands <Dockfile>
HELP;
}


try{
    exit(main($argv));
} catch (Exception $e){
    echo "Got exception: ";
    echo $e;
    exit(1);
}
