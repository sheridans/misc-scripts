<?php
/**
 * Takes National Statistics Postcode Lookup UK Coordinates CSV file from Camden
 * and parses into doctrine migrations output format.
 *
 * Generate doctrine migrations with table and copy/paste the output into
 * your generated migrations file.
 *
 * @author    Sam Sheridan
 * @copyright 2023 Sheridan Computers
 *
 * (https://opendata.camden.gov.uk/Maps/National-Statistics-Postcode-Lookup-UK-Coordinates/77ra-mbbn)
 */

if (count($argv) < 4) {
    echo "Usage: $argv[0] <source.csv> <output.php> <table_name>\n";
    return 1;
}

$source = $argv[1];
$dest = $argv[2];
$tableName = $argv[3];

$outcodes = [];

enum csvField: int
{
    case POSTCODE_1 = 0;
    case POSTCODE_2 = 1;
    case POSTCODE_3 = 2;
    case EASTING = 3;
    case NORTHING = 4;
    case POSITIONAL_QUALITY = 5;
    case LOCAL_AUTHORITY = 6;
    case LONGITUDE = 7;
    case LATITUDE = 8;
    case SPACIAL_ACCURACY = 9;
    case LAST_UPLOAD = 10;
    case LOCATION = 11;
    case SOCRATA_ID = 12;
}

/**
 * Parse CSV line to array
 * Remove any whitespace or double quotes
 *
 * @param string $line CSV text read from file
 * @return array CSV line as array
 */
function csvLineToArray(string $line): array
{
    $pattern = '/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/';

    $split = preg_split($pattern, $line);
    $split = array_map(function ($item) {
        return trim($item, ' "');
    }, $split);


    // sanitise slashes
    return array_map('addslashes', $split);

    //return $split;
}

function getOutcode(string $postcode): string
{
    $outcode = explode(' ', $postcode);
    return str_replace(array('.', ' ', "\n", "\t", "\r"), '', $outcode[0]);
}

function buildOutputText($line, $table_name): ?string
{
    global $outcodes;

    // parse csv line of text to an array
    $line_array = csvLineToArray($line);

    if ($line_array[csvField::POSTCODE_3->value] === 'Postcode 3') {
        return null;
    }


    // get outcode
    $outcode = getOutcode($line_array[csvField::POSTCODE_3->value]);

    // already saved?
    if (in_array($outcode, $outcodes)) {
        return null;
    }

    // stored as processed
    $outcodes[] = $outcode;

    // process the line
    $query = sprintf('$this->connection->insert(\'%s\',[\'postcode\' => \'%s\', \'easting\' => %s, \'northing\' => %s, \'location\' => \'%s\', \'longitude\' => %s, \'latitude\' => %s]);',
        $table_name,
        //$line_array[csvField::POSTCODE_3->value],
        $outcode,
        $line_array[csvField::EASTING->value],
        $line_array[csvField::NORTHING->value],
        $line_array[csvField::LOCAL_AUTHORITY->value],
        $line_array[csvField::LONGITUDE->value],
        $line_array[csvField::LATITUDE->value]
    );
    return $query;
}

function getFileLineCount(string $filename): int
{
    $linecount = 0;

    $handleFile = fopen($filename, "r");

    while (!feof($handleFile)) {
        $line = fgets($handleFile, 4096);

        // We are using PHP_EOL (PHP End of Line)
        //to count number of line breaks in currently loaded data.
        $linecount = $linecount + substr_count($line, PHP_EOL);
    }

    fclose($handleFile);
    return $linecount;
}


if (!is_readable($source)) {
    echo "Source file is not readable, or not found.\n";
    return 1;
}

echo sprintf("Processing File: %s\n", $source);
$lineCount = getFileLineCount($source);
echo sprintf("Lines Found: %d\n", $lineCount);


$hSource = fopen($source, "r");
if (!$hSource) {
    echo "Error reading source file.\n";
    return 1;
}

$hDest = fopen($dest, "w");
if (!$hDest) {
    echo "Error writing to destination file.\n";
    fclose($hSource);

    return 1;
}

$lineNum = 1;
$linesWritten = 0;
echo sprintf("Generating File: %s\n", $dest);
while (($line = fgets($hSource)) !== false) {
    $query = buildOutputText($line, $tableName);
    if ($query !== null) {
        $query .= PHP_EOL;
    }

    // calculate percentage processed
    $filePercent = ($lineNum / $lineCount) * 100;
    echo sprintf("Processing Line: %s (%s%%)\r", $lineNum, number_format($filePercent));

    if (!empty($query)) {
        // write line to destination
        fwrite($hDest, $query);
        $linesWritten++;
    }
    $lineNum++;
}

echo sprintf("\nFile generation complete.\n");
echo sprintf("Lines written: %d\n", $linesWritten);

fclose($hSource);
fclose($hDest);
