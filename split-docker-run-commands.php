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

    echo "Spliting RUN...\n";
    $commands = split_run_commands($commands);

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

    // echo $regeneratedFileContent; die;

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

function split_run_commands($originalCommands){
    $resultCommands = [];

    foreach ($originalCommands as $cmd){
        if ($cmd['type'] !== 'RUN'){
            $resultCommands[] = $cmd;
            continue;
        } 
        
        $nextRunCmdText = '';
        $peddingSpacesComments = [];
        $storeNextRunCmd = function() use (&$resultCommands, &$nextRunCmdText, &$peddingSpacesComments){
            if ($nextRunCmdText){
                $resultCommands[] = [
                    'type' => 'RUN',
                    'text' => $nextRunCmdText,
                ];
                
                $nextRunCmdText = '';
            }

            if ($peddingSpacesComments){
                $resultCommands = array_merge($resultCommands, $peddingSpacesComments);

                $peddingSpacesComments = [];
            }
        };

        $lines = explode("\n", $cmd['text']);
        foreach ($lines as $line){
            $line = ltrim(rtrim(rtrim(rtrim($line), "\\")));
            if (preg_match('/^\s*$/', $line)){
                $peddingSpacesComments[] = [
                    'type' => 'space',
                    'text' => $line,
                ];
                continue;
            }

            if ($line[0] === '#'){
                $peddingSpacesComments[] = [
                    'type' => 'comment',
                    'text' => $line,
                ];
                continue;
            }

            if (substr($line, 0, 2) === '&&'){
                $storeNextRunCmd();
                $nextRunCmdText = substr($line, 3);
            } else {
                $nextRunCmdText .= ($nextRunCmdText ? " \\\n    " : '') . $line;
            }
        }

        $storeNextRunCmd();
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
