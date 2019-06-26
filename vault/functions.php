<?php
/**
 * This file is a part of the CIDRAM package.
 * Homepage: https://cidram.github.io/
 *
 * CIDRAM COPYRIGHT 2016 and beyond by Caleb Mazalevskis (Maikuolan).
 *
 * License: GNU/GPLv2
 * @see LICENSE.txt
 *
 * This file: Functions file (last modified: 2019.06.25).
 */

/**
 * Extends compatibility with CIDRAM to PHP 5.4.x by introducing some simple
 * polyfills for functions introduced with newer versions of PHP.
 */
if (substr(PHP_VERSION, 0, 4) === '5.4.') {
    require $CIDRAM['Vault'] . 'php5.4.x.php';
}

/** Autoloader for CIDRAM classes. */
spl_autoload_register(function ($Class) {
    $Vendor = (($Pos = strpos($Class, "\\", 1)) === false) ? '' : substr($Class, 0, $Pos);
    $File = __DIR__ . '/classes/' . ((!$Vendor || $Vendor === 'CIDRAM') ? '' : $Vendor . '/') . (
        (($Pos = strrpos($Class, "\\")) === false) ? $Class : substr($Class, $Pos + 1)
    ) . '.php';
    if (is_readable($File)) {
        require $File;
    }
});

/**
 * Reads and returns the contents of files.
 *
 * @param string $File Path and filename of the file to read.
 * @return string|bool Content of the file returned by the function (or false
 *      on failure).
 */
$CIDRAM['ReadFile'] = function ($File) {
    if (!is_file($File) || !is_readable($File)) {
        return false;
    }
    /** Default blocksize (128KB). */
    static $Blocksize = 131072;
    $Filesize = filesize($File);
    $Size = ($Filesize && $Blocksize) ? ceil($Filesize / $Blocksize) : 0;
    $Data = '';
    if ($Size > 0) {
        $Handle = fopen($File, 'rb');
        $r = 0;
        while ($r < $Size) {
            $Data .= fread($Handle, $Blocksize);
            $r++;
        }
        fclose($Handle);
    }
    return $Data ?: false;
};

/**
 * Replaces encapsulated substrings within a string using the values of the
 * corresponding elements within an array.
 *
 * @param array $Needle An array containing replacement values.
 * @param string $Haystack The string to work with.
 * @return string The string with its encapsulated substrings replaced.
 */
$CIDRAM['ParseVars'] = function (array $Needle, $Haystack) {
    if (!is_array($Needle) || empty($Haystack)) {
        return '';
    }
    array_walk($Needle, function ($Value, $Key) use (&$Haystack) {
        if (!is_array($Value)) {
            $Haystack = str_replace('{' . $Key . '}', $Value, $Haystack);
        }
    });
    return $Haystack;
};

/**
 * Fetches instructions from the `ignore.dat` file.
 *
 * @return array An array listing the sections that CIDRAM should ignore.
 */
$CIDRAM['FetchIgnores'] = function () use (&$CIDRAM) {
    $IgnoreMe = [];
    $IgnoreFile = $CIDRAM['ReadFile']($CIDRAM['Vault'] . 'ignore.dat');
    if (strpos($IgnoreFile, "\r")) {
        $IgnoreFile = (
            strpos($IgnoreFile, "\r\n") !== false
        ) ? str_replace("\r", '', $IgnoreFile) : str_replace("\r", "\n", $IgnoreFile);
    }
    $IgnoreFile = "\n" . $IgnoreFile . "\n";
    $PosB = -1;
    while (true) {
        $PosA = strpos($IgnoreFile, "\nIgnore ", ($PosB + 1));
        if ($PosA === false) {
            break;
        }
        $PosA += 8;
        if (!$PosB = strpos($IgnoreFile, "\n", $PosA)) {
            break;
        }
        $Tag = substr($IgnoreFile, $PosA, ($PosB - $PosA));
        if (strlen($Tag)) {
            $IgnoreMe[$Tag] = true;
        }
        $PosB--;
    }
    return $IgnoreMe;
};

/**
 * Tests whether $Addr is an IPv4 address, and if it is, expands its potential
 * factors (i.e., constructs an array containing the CIDRs that contain $Addr).
 * Returns false if $Addr is *not* an IPv4 address, and otherwise, returns the
 * contructed array.
 *
 * @param string $Addr Refer to the description above.
 * @param bool $ValidateOnly If true, just checks if the IP is valid only.
 * @param int $FactorLimit Maximum number of CIDRs to return (default: 32).
 * @return bool|array Refer to the description above.
 */
$CIDRAM['ExpandIPv4'] = function ($Addr, $ValidateOnly = false, $FactorLimit = 32) {
    if (!preg_match(
        '/^([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])$/i',
        $Addr,
        $Octets
    )) {
        return false;
    }
    if ($ValidateOnly) {
        return true;
    }
    $CIDRs = [];
    $Base = [0, 0, 0, 0];
    for ($Cycle = 0; $Cycle < 4; $Cycle++) {
        for ($Size = 128, $Step = 0; $Step < 8; $Step++, $Size /= 2) {
            $CIDR = $Step + ($Cycle * 8);
            $Base[$Cycle] = floor($Octets[$Cycle + 1] / $Size) * $Size;
            $CIDRs[$CIDR] = $Base[0] . '.' . $Base[1] . '.' . $Base[2] . '.' . $Base[3] . '/' . ($CIDR + 1);
            if ($CIDR >= $FactorLimit) {
                break 2;
            }
        }
    }
    return $CIDRs;
};

/**
 * Tests whether $Addr is an IPv6 address, and if it is, expands its potential
 * factors (i.e., constructs an array containing the CIDRs that contain $Addr).
 * Returns false if $Addr is *not* an IPv6 address, and otherwise, returns the
 * contructed array.
 *
 * @param string $Addr Refer to the description above.
 * @param bool $ValidateOnly If true, just checks if the IP is valid only.
 * @param int $FactorLimit Maximum number of CIDRs to return (default: 128).
 * @return bool|array Refer to the description above.
 */
$CIDRAM['ExpandIPv6'] = function ($Addr, $ValidateOnly = false, $FactorLimit = 128) {
    /**
     * The REGEX pattern used by this `preg_match` call was adapted from the
     * IPv6 REGEX pattern that can be found at
     * https://sroze.io/regex-ip-v4-et-ipv6-6cc005cabe8c
     */
    if (!preg_match(
        '/^(([\da-f]{1,4}\:){7}[\da-f]{1,4})|(([\da-f]{1,4}\:){6}\:[\da-f]{1' .
        ',4})|(([\da-f]{1,4}\:){5}\:([\da-f]{1,4}\:)?[\da-f]{1,4})|(([\da-f]' .
        '{1,4}\:){4}\:([\da-f]{1,4}\:){0,2}[\da-f]{1,4})|(([\da-f]{1,4}\:){3' .
        '}\:([\da-f]{1,4}\:){0,3}[\da-f]{1,4})|(([\da-f]{1,4}\:){2}\:([\da-f' .
        ']{1,4}\:){0,4}[\da-f]{1,4})|(([\da-f]{1,4}\:){6}((\b((25[0-5])|(1\d' .
        '{2})|(2[0-4]\d)|(\d{1,2}))\b).){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)' .
        '|(\d{1,2}))\b))|(([\da-f]{1,4}\:){0,5}\:((\b((25[0-5])|(1\d{2})|(2[' .
        '0-4]\d)|(\d{1,2}))\b).){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2' .
        '}))\b))|(\:\:([\da-f]{1,4}\:){0,5}((\b((25[0-5])|(1\d{2})|(2[0-4]\d' .
        ')|(\d{1,2}))\b).){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)' .
        ')|([\da-f]{1,4}\:\:([\da-f]{1,4}\:){0,5}[\da-f]{1,4})|(\:\:([\da-f]' .
        '{1,4}\:){0,6}[\da-f]{1,4})|(([\da-f]{1,4}\:){1,7}\:)$/i',
    $Addr)) {
        return false;
    }
    if ($ValidateOnly) {
        return true;
    }
    $NAddr = $Addr;
    if (preg_match('/^\:\:/i', $NAddr)) {
        $NAddr = '0' . $NAddr;
    }
    if (preg_match('/\:\:$/i', $NAddr)) {
        $NAddr .= '0';
    }
    if (strpos($NAddr, '::') !== false) {
        $Key = 7 - substr_count($Addr, ':');
        $Arr = [':0:', ':0:0:', ':0:0:0:', ':0:0:0:0:', ':0:0:0:0:0:', ':0:0:0:0:0:0:'];
        if (!isset($Arr[$Key])) {
            return false;
        }
        $NAddr = str_replace('::', $Arr[$Key], $Addr);
        unset($Arr, $Key);
    }
    $NAddr = explode(':', $NAddr);
    if (count($NAddr) !== 8) {
        return false;
    }
    for ($i = 0; $i < 8; $i++) {
        $NAddr[$i] = hexdec($NAddr[$i]);
    }
    $CIDRs = [];
    $Base = [0, 0, 0, 0, 0, 0, 0, 0];
    for ($Cycle = 0; $Cycle < 8; $Cycle++) {
        for ($Size = 32768, $Step = 0; $Step < 16; $Step++, $Size /= 2) {
            $CIDR = $Step + ($Cycle * 16);
            $Base[$Cycle] = dechex(floor($NAddr[$Cycle] / $Size) * $Size);
            $CIDRs[$CIDR] = $Base[0] . ':' . $Base[1] . ':' . $Base[2] . ':' . $Base[3] . ':' . $Base[4] . ':' . $Base[5] . ':' . $Base[6] . ':' . $Base[7] . '/' . ($CIDR + 1);
            if ($CIDR >= $FactorLimit) {
                break 2;
            }
        }
    }
    if ($FactorLimit > 128) {
        $FactorLimit = 128;
    }
    for ($CIDR = 0; $CIDR < $FactorLimit; $CIDR++) {
        if (strpos($CIDRs[$CIDR], '::') !== false) {
            $CIDRs[$CIDR] = preg_replace('/(\:0)*\:\:(0\:)*/i', '::', $CIDRs[$CIDR], 1);
            $CIDRs[$CIDR] = str_replace('::0/', '::/', $CIDRs[$CIDR]);
            continue;
        }
        if (strpos($CIDRs[$CIDR], ':0:0/') !== false) {
            $CIDRs[$CIDR] = preg_replace('/(\:0){2,}\//i', '::/', $CIDRs[$CIDR], 1);
            continue;
        }
        if (strpos($CIDRs[$CIDR], ':0:0:') !== false) {
            $CIDRs[$CIDR] = preg_replace('/(\:0)+\:(0\:)+/i', '::', $CIDRs[$CIDR], 1);
            $CIDRs[$CIDR] = str_replace('::0/', '::/', $CIDRs[$CIDR]);
            continue;
        }
    }
    return $CIDRs;
};

/**
 * Gets tags from signature files.
 *
 * @param string $Haystack The haystack to search within for the target tag.
 * @param int $Offset The position to start searching from within the haystack.
 * @param string $Tag The tag we're trying to get.
 * @param string $DefTag The value to use when the target tag isn't found.
 * @return string The value of the tag we're trying to get, or of DefTag.
 */
$CIDRAM['Getter'] = function ($Haystack, $Offset, $Tag, $DefTag) {
    $Key = "\n" . $Tag . ': ';
    $KeyLen = strlen($Key);
    return (
        ($PosX = strpos($Haystack, $Key, $Offset)) &&
        ($PosY = strpos($Haystack, "\n", $PosX + 1)) &&
        !substr_count($Haystack, "\n\n", $Offset, $PosX - $Offset + 1)
    ) ? substr($Haystack, $PosX + $KeyLen, $PosY - $PosX - $KeyLen) : $DefTag;
};

/**
 * Checks CIDRs (generally, potential factors expanded from IP addresses)
 * against the IPv4/IPv6 signature files, and if any matches are found,
 * increments `$CIDRAM['BlockInfo']['SignatureCount']`, and
 * appends to `$CIDRAM['BlockInfo']['ReasonMessage']`.
 *
 * @param array $Files Which IPv4/IPv6 signature files to check against.
 * @param array $Factors Which CIDRs/factors to check against.
 * @throws Exception if a triggered signature indicates a non-existent file to run.
 * @return bool Returns true.
 */
$CIDRAM['CheckFactors'] = function (array $Files, array $Factors) use (&$CIDRAM) {
    $Counts = [
        'Files' => count($Files),
        'Factors' => count($Factors)
    ];
    if (!isset($CIDRAM['FileCache'])) {
        $CIDRAM['FileCache'] = [];
    }
    for ($FileIndex = 0; $FileIndex < $Counts['Files']; $FileIndex++) {
        $Files[$FileIndex] = (
            strpos($Files[$FileIndex], ':') === false
        ) ? $Files[$FileIndex] : substr($Files[$FileIndex], strpos($Files[$FileIndex], ':') + 1);
        if (!$Files[$FileIndex]) {
            continue;
        }
        if ($Counts['Factors'] === 32) {
            $DefTag = $Files[$FileIndex] . '-IPv4';
        } elseif ($Counts['Factors'] === 128) {
            $DefTag = $Files[$FileIndex] . '-IPv6';
        } else {
            $DefTag = $Files[$FileIndex] . '-Unknown';
        }
        $FileExtension = strtolower(substr($Files[$FileIndex], -4));
        if (!isset($CIDRAM['FileCache'][$Files[$FileIndex]])) {
            $CIDRAM['FileCache'][$Files[$FileIndex]] = $CIDRAM['ReadFile']($CIDRAM['Vault'] . $Files[$FileIndex]);
        }
        if (!$Files[$FileIndex] = $CIDRAM['FileCache'][$Files[$FileIndex]]) {
            continue;
        }
        if (
            $FileExtension === '.csv' &&
            strpos($Files[$FileIndex], "\n") === false &&
            strpos($Files[$FileIndex], "\r") === false &&
            strpos($Files[$FileIndex], ",") !== false
        ) {
            $Files[$FileIndex] = ',' . $Files[$FileIndex] . ',';
            $SigFormat = 'CSV';
        } else {
            $SigFormat = 'DAT';
        }
        if ($Counts['Factors'] === 32) {
            if ($SigFormat === 'CSV') {
                $NoCIDR = ',' . substr($Factors[31], 0, -3) . ',';
                $LastCIDR = ',' . $Factors[31] . ',';
            } else {
                $NoCIDR = "\n" . substr($Factors[31], 0, -3) . ' ';
                $LastCIDR = "\n" . $Factors[31] . ' ';
            }
        } elseif ($Counts['Factors'] === 128) {
            if ($SigFormat === 'CSV') {
                $NoCIDR = ',' . substr($Factors[127], 0, -4) . ',';
                $LastCIDR = ',' . $Factors[127] . ',';
            } else {
                $NoCIDR = "\n" . substr($Factors[127], 0, -4) . ' ';
                $LastCIDR = "\n" . $Factors[127] . ' ';
            }
        }
        if (strpos($Files[$FileIndex], $NoCIDR) !== false) {
            $Files[$FileIndex] = str_replace($NoCIDR, $LastCIDR, $Files[$FileIndex]);
        }
        if ($SigFormat === 'CSV') {
            $LN = ' ("' . $DefTag . '", L0:F' . $FileIndex . ')';
            for ($FactorIndex = 0; $FactorIndex < $Counts['Factors']; $FactorIndex++) {
                if ($Infractions = substr_count($Files[$FileIndex], ',' . $Factors[$FactorIndex] . ',')) {
                    if (!$CIDRAM['CIDRAM_sapi']) {
                        $CIDRAM['BlockInfo']['ReasonMessage'] = $CIDRAM['L10N']->getString('ReasonMessage_Generic');
                        if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
                            $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
                        }
                        $CIDRAM['BlockInfo']['WhyReason'] .= $CIDRAM['L10N']->getString('Short_Generic') . $LN;
                    }
                    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
                        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
                    }
                    $CIDRAM['BlockInfo']['Signatures'] .= $Factors[$FactorIndex];
                    $CIDRAM['BlockInfo']['SignatureCount'] += $Infractions;
                }
            }
            continue;
        }
        if (strpos($Files[$FileIndex], "\r") !== false) {
            $Files[$FileIndex] =
                (strpos($Files[$FileIndex], "\r\n")) ?
                str_replace("\r", '', $Files[$FileIndex]) :
                str_replace("\r", "\n", $Files[$FileIndex]);
        }
        $Files[$FileIndex] = "\n" . $Files[$FileIndex] . "\n";
        for ($FactorIndex = 0; $FactorIndex < $Counts['Factors']; $FactorIndex++) {
            $PosB = -1;
            while (true) {
                $PosA = strpos($Files[$FileIndex], "\n" . $Factors[$FactorIndex] . ' ', ($PosB + 1));
                if ($PosA === false) {
                    break;
                }
                $PosA += strlen($Factors[$FactorIndex]) + 2;
                if (!$PosB = strpos($Files[$FileIndex], "\n", $PosA)) {
                    break;
                }
                if ($DefersTo = $CIDRAM['Getter']($Files[$FileIndex], $PosA, 'Defers to', '')) {
                    $DefersTo = preg_quote($DefersTo);
                    if (
                        preg_match('~(?:^|,)' . $DefersTo . '(?:$|,)~i', $CIDRAM['Config']['signatures']['ipv4']) ||
                        preg_match('~(?:^|,)' . $DefersTo . '(?:$|,)~i', $CIDRAM['Config']['signatures']['ipv6'])
                    ) {
                        continue;
                    }
                }
                if (
                    ($Expires = $CIDRAM['Getter']($Files[$FileIndex], $PosA, 'Expires', '')) &&
                    ($Expires = $CIDRAM['FetchExpires']($Expires)) &&
                    $Expires < $CIDRAM['Now']
                ) {
                    continue;
                }
                $Tag = $CIDRAM['Getter']($Files[$FileIndex], $PosA, 'Tag', $DefTag);
                if (!empty($CIDRAM['Ignore'][$Tag])) {
                    continue;
                }
                $Origin = (
                    $Origin = $CIDRAM['Getter']($Files[$FileIndex], $PosA, 'Origin', '')
                ) ? ', [' . $Origin . ']' : '';
                if (
                    ($PosX = strpos($Files[$FileIndex], "\n---\n", $PosA)) &&
                    ($PosY = strpos($Files[$FileIndex], "\n\n", ($PosX + 1))) &&
                    !substr_count($Files[$FileIndex], "\n\n", $PosA, ($PosX - $PosA + 1))
                ) {
                    if (!isset($YAML)) {
                        $YAML = new \Maikuolan\Common\YAML();
                    }
                    $YAML->process(substr($Files[$FileIndex], ($PosX + 5), ($PosY - $PosX - 5)), $CIDRAM['Config']);
                }
                $LN = ' ("' . $Tag . '", L' . substr_count($Files[$FileIndex], "\n", 0, $PosA) . ':F' . $FileIndex . $Origin . ')';
                $Signature = substr($Files[$FileIndex], $PosA, ($PosB - $PosA));
                if (!$Category = substr($Signature, 0, strpos($Signature, ' '))) {
                    $Category = $Signature;
                } else {
                    $Signature = substr($Signature, strpos($Signature, ' ') + 1);
                }
                $RunExitCode = 0;
                if ($Category === 'Run' && !$CIDRAM['CIDRAM_sapi']) {
                    if (!isset($CIDRAM['RunParamResCache'])) {
                        $CIDRAM['RunParamResCache'] = [];
                    }
                    if (isset($CIDRAM['RunParamResCache'][$Signature]) && is_object($CIDRAM['RunParamResCache'][$Signature])) {
                        $RunExitCode = $CIDRAM['RunParamResCache'][$Signature]($Factors, $FactorIndex, $LN, $Tag);
                    } else {
                        if (file_exists($CIDRAM['Vault'] . $Signature)) {
                            require_once $CIDRAM['Vault'] . $Signature;
                        } else {
                            throw new \Exception($CIDRAM['ParseVars'](
                                ['FileName' => $Signature],
                                '[CIDRAM] ' . $CIDRAM['L10N']->getString('Error_MissingRequire')
                            ));
                        }
                    }
                }
                if ($Category === 'Whitelist' || $RunExitCode === 3) {
                    $CIDRAM['ZeroOutBlockInfo'](true);
                    break 3;
                }
                if ($Category === 'Greylist' || $RunExitCode === 2) {
                    $CIDRAM['ZeroOutBlockInfo']();
                    break 2;
                }
                if ($Category === 'Deny') {
                    $DenyMatched = false;
                    if (!$CIDRAM['CIDRAM_sapi']) {
                        foreach ([
                            ['Type' => 'Bogon', 'Config' => 'block_bogons', 'ReasonLong' => 'ReasonMessage_Bogon', 'ReasonShort' => 'Short_Bogon'],
                            ['Type' => 'Cloud', 'Config' => 'block_cloud', 'ReasonLong' => 'ReasonMessage_Cloud', 'ReasonShort' => 'Short_Cloud'],
                            ['Type' => 'Generic', 'Config' => 'block_generic', 'ReasonLong' => 'ReasonMessage_Generic', 'ReasonShort' => 'Short_Generic'],
                            ['Type' => 'Legal', 'Config' => 'block_legal', 'ReasonLong' => 'ReasonMessage_Legal', 'ReasonShort' => 'Short_Legal'],
                            ['Type' => 'Malware', 'Config' => 'block_malware', 'ReasonLong' => 'ReasonMessage_Malware', 'ReasonShort' => 'Short_Malware'],
                            ['Type' => 'Proxy', 'Config' => 'block_proxies', 'ReasonLong' => 'ReasonMessage_Proxy', 'ReasonShort' => 'Short_Proxy'],
                            ['Type' => 'Spam', 'Config' => 'block_spam', 'ReasonLong' => 'ReasonMessage_Spam', 'ReasonShort' => 'Short_Spam']
                        ] as $Params) {
                            if ($Signature === $Params['Type']) {
                                if (empty($CIDRAM['Config']['signatures'][$Params['Config']])) {
                                    continue 2;
                                }
                                $CIDRAM['BlockInfo']['ReasonMessage'] = $CIDRAM['L10N']->getString($Params['ReasonLong']);
                                if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
                                    $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
                                }
                                $CIDRAM['BlockInfo']['WhyReason'] .= $CIDRAM['L10N']->getString($Params['ReasonShort']) . $LN;
                                $DenyMatched = true;
                                break;
                            }
                        }
                    }
                    if (!$DenyMatched) {
                        $CIDRAM['BlockInfo']['ReasonMessage'] = $Signature;
                        if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
                            $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
                        }
                        $CIDRAM['BlockInfo']['WhyReason'] .= $Signature . $LN;
                    }
                    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
                        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
                    }
                    $CIDRAM['BlockInfo']['Signatures'] .= $Factors[$FactorIndex];
                    $CIDRAM['BlockInfo']['SignatureCount']++;
                }
            }
        }
    }
    return true;
};

/**
 * Initialises all IPv4/IPv6 tests.
 *
 * @param string $Addr The IP address to check.
 * @param bool $Retain Specifies whether we need to retain factors for later.
 * @throws Exception if CheckFactors throws an exception.
 * @return bool Returns false if all tests fail, or true otherwise.
 */
$CIDRAM['RunTests'] = function ($Addr, $Retain = false) use (&$CIDRAM) {
    if (!isset($CIDRAM['BlockInfo'])) {
        return false;
    }
    if (!isset($CIDRAM['Ignore'])) {
        $CIDRAM['Ignore'] = $CIDRAM['FetchIgnores']();
    }
    $CIDRAM['Whitelisted'] = false;
    $CIDRAM['LastTestIP'] = 0;
    if ($IPv4Factors = $CIDRAM['ExpandIPv4']($Addr)) {
        $IPv4Files = empty(
            $CIDRAM['Config']['signatures']['ipv4']
        ) ? [] : explode(',', $CIDRAM['Config']['signatures']['ipv4']);
        try {
            $IPv4Test = $CIDRAM['CheckFactors']($IPv4Files, $IPv4Factors);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        if ($IPv4Test) {
            $CIDRAM['LastTestIP'] = 4;
            if ($Retain) {
                $CIDRAM['Factors'] = $IPv4Factors;
            }
        }
    } else {
        $IPv4Test = false;
    }
    if ($IPv6Factors = $CIDRAM['ExpandIPv6']($Addr)) {
        $IPv6Files = empty(
            $CIDRAM['Config']['signatures']['ipv6']
        ) ? [] : explode(',', $CIDRAM['Config']['signatures']['ipv6']);
        try {
            $IPv6Test = $CIDRAM['CheckFactors']($IPv6Files, $IPv6Factors);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        if ($IPv6Test) {
            $CIDRAM['LastTestIP'] = 6;
            if ($Retain) {
                $CIDRAM['Factors'] = $IPv6Factors;
            }
        }
    } else {
        $IPv6Test = false;
    }
    return ($IPv4Test || $IPv6Test);
};

/**
 * Zeros out blockinfo and optionally sets the whitelisted flag.
 *
 * @param bool $Whitelist Whether to set the whitelisted flag.
 */
$CIDRAM['ZeroOutBlockInfo'] = function ($Whitelist = false) use (&$CIDRAM) {
    $CIDRAM['BlockInfo']['Signatures'] = '';
    $CIDRAM['BlockInfo']['ReasonMessage'] = '';
    $CIDRAM['BlockInfo']['WhyReason'] = '';
    $CIDRAM['BlockInfo']['SignatureCount'] = 0;
    if ($Whitelist) {
        $CIDRAM['Whitelisted'] = true;
    }
};

/**
 * A very simple closure for preparing validator/fixer messages in CLI-mode.
 *
 * @param string $lvl Error level.
 * @param string $msg The unprepared message (in).
 * @return string The prepared message (out).
 */
$CIDRAM['ValidatorMsg'] = function ($lvl, $msg) {
    return wordwrap(sprintf(' [%s] %s', $lvl, $msg), 78, "\n ") . "\n\n";
};

/**
 * Reduces code duplicity (the contained code used by multiple parts of the
 * script for dealing with expiry tags).
 *
 * @param string $in Expiry tag.
 * @return int|bool A unix timestamp representing the expiry tag, or false if
 *      the expiry tag doesn't contain a valid ISO 8601 date/time.
 */
$CIDRAM['FetchExpires'] = function ($in) {
    static $CommonPart = '([12]\d{3})(?:\xe2\x88\x92|[\x2d-\x2f\x5c])?(0[1-9]|1[0-2])(?:\xe2\x88\x92|[\x2d-\x2f\x5c])?(0[1-9]|[1-2]\d|3[01])';
    if (
        preg_match('/^' . $CommonPart . '\x20?T?([01]\d|2[0-3])[\x2d\x2e\x3a]?([0-5]\d)[\x2d\x2e\x3a]?([0-5]\d)$/i', $in, $Arr) ||
        preg_match('/^' . $CommonPart . '\x20?T?([01]\d|2[0-3])[\x2d\x2e\x3a]?([0-5]\d)$/i', $in, $Arr) ||
        preg_match('/^' . $CommonPart . '\x20?T?([01]\d|2[0-3])$/i', $in, $Arr) ||
        preg_match('/^' . $CommonPart . '$/i', $in, $Arr) ||
        preg_match('/^([12]\d{3})(?:\xe2\x88\x92|[\x2d-\x2f\x5c])?(0[1-9]|1[0-2])$/i', $in, $Arr) ||
        preg_match('/^([12]\d{3})$/i', $in, $Arr)
    ) {
        $Arr = [
            (int)$Arr[1],
            isset($Arr[2]) ? (int)$Arr[2] : 1,
            isset($Arr[3]) ? (int)$Arr[3] : 1,
            isset($Arr[4]) ? (int)$Arr[4] : 0,
            isset($Arr[5]) ? (int)$Arr[5] : 0,
            isset($Arr[6]) ? (int)$Arr[6] : 0
        ];
        $Expires = mktime($Arr[3], $Arr[4], $Arr[5], $Arr[1], $Arr[2], $Arr[0]);
        return $Expires ?: false;
    }
    return false;
};

/**
 * A simple closure for replacing date/time placeholders with corresponding
 * date/time information. Used by the logfiles and some timestamps.
 *
 * @param int $Time A unix timestamp.
 * @param string|array $In An input or an array of inputs to manipulate.
 * @return string|array The adjusted input(/s).
 */
$CIDRAM['TimeFormat'] = function ($Time, $In) use (&$CIDRAM) {
    $Time = date('dmYHisDMP', $Time);
    $values = [
        'dd' => substr($Time, 0, 2),
        'mm' => substr($Time, 2, 2),
        'yyyy' => substr($Time, 4, 4),
        'yy' => substr($Time, 6, 2),
        'hh' => substr($Time, 8, 2),
        'ii' => substr($Time, 10, 2),
        'ss' => substr($Time, 12, 2),
        'Day' => substr($Time, 14, 3),
        'Mon' => substr($Time, 17, 3),
        'tz' => substr($Time, 20, 3) . substr($Time, 24, 2),
        't:z' => substr($Time, 20, 6)
    ];
    $values['d'] = (int)$values['dd'];
    $values['m'] = (int)$values['mm'];
    return is_array($In) ? array_map(function ($Item) use (&$values, &$CIDRAM) {
        return $CIDRAM['ParseVars']($values, $Item);
    }, $In) : $CIDRAM['ParseVars']($values, $In);
};

/**
 * Fix incorrect typecasting for some for some variables that sometimes default
 * to strings instead of booleans or integers.
 *
 * @param mixed $Var The variable to fix (passed by reference).
 * @param string $Type The type (or pseudo-type) to cast the variable to.
 */
$CIDRAM['AutoType'] = function (&$Var, $Type = '') use (&$CIDRAM) {
    if (in_array($Type, ['string', 'timezone', 'checkbox'], true)) {
        $Var = (string)$Var;
    } elseif ($Type === 'int') {
        $Var = (int)$Var;
    } elseif ($Type === 'float') {
        $Var = (float)$Var;
    } elseif ($Type === 'bool') {
        $Var = (strtolower($Var) !== 'false' && $Var);
    } elseif ($Type === 'kb') {
        $Var = $CIDRAM['ReadBytes']((string)$Var, 1);
    } else {
        $LVar = strtolower($Var);
        if ($LVar === 'true') {
            $Var = true;
        } elseif ($LVar === 'false') {
            $Var = false;
        } elseif ($Var !== true && $Var !== false) {
            $Var = (int)$Var;
        }
    }
};

/**
 * Used to send cURL requests.
 *
 * @param string $URI The resource to request.
 * @param array $Params An optional associative array of key-value pairs to
 *      send with the request.
 * @param int $Timeout An optional timeout limit.
 * @param int $Depth Recursion depth of the current closure instance.
 * @return string The results of the request, or an empty string upon failure.
 */
$CIDRAM['Request'] = function ($URI, array $Params = [], $Timeout = -1, $Depth = 0) use (&$CIDRAM) {

    /** Fetch channel information. */
    if (!isset($CIDRAM['Channels'])) {
        $CIDRAM['Channels'] = (
            $Channels = $CIDRAM['ReadFile']($CIDRAM['Vault'] . 'channels.yaml')
        ) ? (new \Maikuolan\Common\YAML($Channels))->Data : [];
        if (!isset($CIDRAM['Channels']['Triggers'])) {
            $CIDRAM['Channels']['Triggers'] = [];
        }
    }

    /** Test channel triggers. */
    foreach ($CIDRAM['Channels']['Triggers'] as $TriggerName => $TriggerURI) {
        if (
            !isset($CIDRAM['Channels'][$TriggerName]) ||
            !is_array($CIDRAM['Channels'][$TriggerName]) ||
            substr($URI, 0, strlen($TriggerURI)) !== $TriggerURI
        ) {
            continue;
        }
        foreach ($CIDRAM['Channels'][$TriggerName] as $Channel => $Options) {
            if (!is_array($Options) || !isset($Options[$TriggerName])) {
                continue;
            }
            $Len = strlen($Options[$TriggerName]);
            if (substr($URI, 0, $Len) !== $Options[$TriggerName]) {
                continue;
            }
            unset($Options[$TriggerName]);
            if (empty($Options) || $CIDRAM['in_csv'](key($Options), $CIDRAM['Config']['general']['disabled_channels'])) {
                continue;
            }
            $AlternateURI = current($Options) . substr($URI, $Len);
            break;
        }
        if ($CIDRAM['in_csv']($TriggerName, $CIDRAM['Config']['general']['disabled_channels'])) {
            if (isset($AlternateURI)) {
                return $CIDRAM['Request']($AlternateURI, $Params, $Timeout, $Depth);
            }
            return '';
        }
        break;
    }

    /** Initialise the cURL session. */
    $Request = curl_init($URI);

    $LCURI = strtolower($URI);
    $SSL = (substr($LCURI, 0, 6) === 'https:');

    curl_setopt($Request, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($Request, CURLOPT_HEADER, false);
    if (empty($Params)) {
        curl_setopt($Request, CURLOPT_POST, false);
    } else {
        curl_setopt($Request, CURLOPT_POST, true);
        curl_setopt($Request, CURLOPT_POSTFIELDS, $Params);
    }
    if ($SSL) {
        curl_setopt($Request, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($Request, CURLOPT_SSL_VERIFYPEER, false);
    }
    curl_setopt($Request, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($Request, CURLOPT_MAXREDIRS, 1);
    curl_setopt($Request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($Request, CURLOPT_TIMEOUT, ($Timeout > 0 ? $Timeout : $CIDRAM['Timeout']));
    curl_setopt($Request, CURLOPT_USERAGENT, $CIDRAM['ScriptUA']);

    /** Execute and get the response. */
    $Response = curl_exec($Request);

    /** Check for problems (e.g., resource not found, server errors, etc). */
    if (($Info = curl_getinfo($Request)) && is_array($Info)) {

        /** Request failed. Try again using an alternative address. */
        if (isset($Info['http_code']) && $Info['http_code'] >= 400 && isset($AlternateURI) && $Depth < 3) {
            curl_close($Request);
            return $CIDRAM['Request']($AlternateURI, $Params, $Timeout, $Depth + 1);
        }

    }

    /** Close the cURL session. */
    curl_close($Request);

    /** Return the results of the request. */
    return $Response;

};

/**
 * Performs reverse DNS lookups for IP addresses, to resolve their hostnames.
 * This is functionally equivalent to the in-built "gethostbyaddr" PHP
 * function, but with the added benefit of being able to specify which DNS
 * servers to use for lookups, and of being able to enforce timeouts, which
 * should help to avoid some of the problems normally encountered by using
 * "gethostbyaddr".
 *
 * @param string $Addr The IP address to look up.
 * @param string $DNS An optional, comma delimited list of DNS servers to use.
 * @param int $Timeout The timeout limit (optional; defaults to 5 seconds).
 * @return string The hostname on success, or the IP address on failure.
 */
$CIDRAM['DNS-Reverse'] = function ($Addr, $DNS = '', $Timeout = 5) use (&$CIDRAM) {

    /** Shouldn't try to reverse localhost addresses; There'll be problems. */
    if ($Addr === '127.0.0.1' || $Addr === '::1') {
        return 'localhost';
    }

    /** We've already got it cached. We can return the results early. */
    if (isset($CIDRAM['DNS-Reverses'][$Addr]['Host'])) {
        return $CIDRAM['DNS-Reverses'][$Addr]['Host'];
    }

    /** The IP address is IPv4. */
    if (strpos($Addr, '.') !== false && strpos($Addr, ':') === false && preg_match(
        '/^([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])' .
        '\.([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])$/i',
    $Addr, $Octets)) {
        $Lookup =
            chr(strlen($Octets[4])) . $Octets[4] .
            chr(strlen($Octets[3])) . $Octets[3] .
            chr(strlen($Octets[2])) . $Octets[2] .
            chr(strlen($Octets[1])) . $Octets[1] .
            "\7in-addr\4arpa\0\0\x0c\0\1";
    }

    /** The IP address is IPv6. */
    elseif (strpos($Addr, '.') === false && strpos($Addr, ':') !== false && $CIDRAM['ExpandIPv6']($Addr, true)) {
        $Lookup = $Addr;
        if (strpos($Addr, '::') !== false) {
            $Repeat = 8 - substr_count($Addr, ':');
            $Lookup = str_replace('::', str_repeat(':0', ($Repeat < 1 ? 0 : $Repeat)) . ':', $Lookup);
        }
        while (strlen($Lookup) < 39) {
            $Lookup = preg_replace(
                ['/^:/', '/:$/', '/^([\da-f]{1,3}):/i', '/:([\da-f]{1,3})$/i', '/:([\da-f]{1,3}):/i'],
                ['0:', ':0', '0\1:', ':0\1', ':0\1:'],
                $Lookup
            );
        }
        $Lookup = strrev(preg_replace(['/\:/', '/(.)/'], ['', "\\1\1"], $Lookup)) . "\3ip6\4arpa\0\0\x0c\0\1";
    }

    /** The IP address is.. wrong. Let's exit the closure. */
    else {
        return $Addr;
    }

    /** Sending UDP is usually pointless if we're not on root. */
    if (!isset($CIDRAM['Root'])) {
        $CIDRAM['Root'] = (!function_exists('posix_getuid') || posix_getuid() === 0);
    }

    /** Use gethostbyaddr if enabled and if we anticipate UDP failing. */
    if (!$CIDRAM['Root'] && $CIDRAM['Config']['general']['allow_gethostbyaddr_lookup']) {
        return $CIDRAM['DNS-Reverse-Fallback']($Addr);
    }

    /** Some safety mechanisms. */
    if (!isset($CIDRAM['_allow_url_fopen'])) {
        $CIDRAM['_allow_url_fopen'] = ini_get('allow_url_fopen');
        $CIDRAM['_allow_url_fopen'] = !(!$CIDRAM['_allow_url_fopen'] || $CIDRAM['_allow_url_fopen'] == 'Off');
    }
    if (!$CIDRAM['Root'] || empty($Lookup) || !function_exists('fsockopen') || !$CIDRAM['_allow_url_fopen']) {
        return $Addr;
    }

    $CIDRAM['DNS-Reverses'][$Addr] = ['Host' => $Addr, 'Time' => $CIDRAM['Now'] + 21600];
    $CIDRAM['DNS-Reverses-Modified'] = true;

    /** DNS is disabled. Let's exit the closure. */
    if (!$DNS && !$DNS = $CIDRAM['Config']['general']['default_dns']) {
        return ($CIDRAM['Config']['general']['allow_gethostbyaddr_lookup']) ? $CIDRAM['DNS-Reverse-Fallback']($Addr) : $Addr;
    }

    /** Expand list of lookup servers. */
    $DNS = explode(',', $DNS);

    /** UDP padding. */
    $LeftPad = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT) . "\1\0\0\1\0\0\0\0\0\0";

    /** Perform the lookup. */
    foreach ($DNS as $Server) {
        if (!empty($Response) || !$Server) {
            break;
        }

        $Handle = fsockopen('udp://' . $Server, 53);
        if ($Handle !== false) {
            fwrite($Handle, $LeftPad . $Lookup);
            stream_set_timeout($Handle, $Timeout);
            stream_set_blocking($Handle, true);
            $Response = fread($Handle, 1024);
            fclose($Handle);
        }
    }

    /** No response, or failed lookup. Let's exit the closure. */
    if (empty($Response)) {
        return (
            $CIDRAM['Config']['general']['allow_gethostbyaddr_lookup']
        ) ? $CIDRAM['DNS-Reverse-Fallback']($Addr) : ($CIDRAM['DNS-Reverses'][$Addr]['Host'] = $Addr);
    }

    /** We got a response! Now let's process it accordingly. */
    $Host = '';
    if (($Pos = strpos($Response, $Lookup)) !== false) {
        $Pos += strlen($Lookup) + 12;
        while (($Byte = substr($Response, $Pos, 1)) && $Byte !== "\0") {
            if ($Host) {
                $Host .= '.';
            }
            $Len = hexdec(bin2hex($Byte));
            $Host .= substr($Response, $Pos + 1, $Len);
            $Pos += 1 + $Len;
        }
    }

    /** Return results. */
    return $CIDRAM['DNS-Reverses'][$Addr]['Host'] = preg_replace('/[^:\da-z._~-]/i', '', $Host) ?: $Addr;

};

/** Aliases for "DNS-Reverse". */
$CIDRAM['DNS-Reverse-IPv4'] = $CIDRAM['DNS-Reverse-IPv6'] = $CIDRAM['DNS-Reverse'];

/**
 * Fallback for failed lookups.
 *
 * @param string $Addr The IP address to look up.
 * @return string The results of gethostbyaddr(), or the IP address verbatim.
 */
$CIDRAM['DNS-Reverse-Fallback'] = function ($Addr) use (&$CIDRAM) {
    $CIDRAM['DNS-Reverses'][$Addr] = ['Host' => $Addr, 'Time' => $CIDRAM['Now'] + 21600];
    $CIDRAM['DNS-Reverses-Modified'] = true;

    /** Return results. */
    return $CIDRAM['DNS-Reverses'][$Addr]['Host'] = preg_replace('/[^:\da-z._~-]/i', '', gethostbyaddr($Addr)) ?: $Addr;

};

/**
 * Performs forward DNS lookups for hostnames, to resolve their IP address.
 * This is functionally equivalent to the in-built PHP function
 * "gethostbyname", but with the added benefits of having IPv6 support and of
 * being able to enforce timeout limits, which should help to avoid some of the
 * problems normally associated with using "gethostbyname").
 *
 * @param string $Host The hostname to look up.
 * @param int $Timeout The timeout limit (optional; defaults to 5 seconds).
 * @return string The IP address on success, or an empty string on failure.
 */
$CIDRAM['DNS-Resolve'] = function ($Host, $Timeout = 5) use (&$CIDRAM) {
    if (isset($CIDRAM['DNS-Forwards'][$Host]['IPAddr'])) {
        return $CIDRAM['DNS-Forwards'][$Host]['IPAddr'];
    }
    $Host = urlencode($Host);
    if (($HostLen = strlen($Host)) > 253) {
        return '';
    }
    static $Valid = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-._~';

    $CIDRAM['DNS-Forwards'][$Host] = ['IPAddr' => '', 'Time' => $CIDRAM['Now'] + 21600];
    $CIDRAM['DNS-Forwards-Modified'] = true;

    $URI = 'https://dns.google.com/resolve?name=' . urlencode($Host) . '&random_padding=';
    $PadLen = 204 - $HostLen;
    if ($PadLen < 1) {
        $PadLen = 972 - $HostLen;
    }
    while ($PadLen > 0) {
        $PadLen--;
        $URI .= str_shuffle($Valid)[0];
    }

    if (!$Results = json_decode($CIDRAM['Request']($URI, [], $Timeout), true)) {
        return '';
    }
    return $CIDRAM['DNS-Forwards'][$Host]['IPAddr'] = empty(
        $Results['Answer'][0]['data']
    ) ? '' : preg_replace('/[^\da-f.:]/i', '', $Results['Answer'][0]['data']);
};

/**
 * Used to identify when bots ghost/masquerade as popular search engines and
 * social media tools. Tracking is disabled for legitimate requests, while
 * ghosted/faked requests are blocked. If DNS is unresolvable and/or if a bot's
 * identity can't be verified, no action is taken (i.e., tracking isn't messed
 * with and the request isn't blocked).
 *
 * @param string|array $Domains Accepted domain/hostname partials.
 * @param string $Friendly A friendly name to use in logfiles.
 * @param array $Options Various options that can be passed to the closure.
 * @return bool Returns true when a determination is successfully made, and
 *      false when a determination isn't able to be made.
 */
$CIDRAM['DNS-Reverse-Forward'] = function ($Domains, $Friendly, array $Options = []) use (&$CIDRAM) {

    /** Fetch the hostname. */
    if (empty($CIDRAM['Hostname'])) {
        $CIDRAM['Hostname'] = $CIDRAM['DNS-Reverse']($CIDRAM['BlockInfo']['IPAddr']);
    }

    /** Do nothing more if we weren't able to resolve the DNS hostname. */
    if (!$CIDRAM['Hostname'] || $CIDRAM['Hostname'] === $CIDRAM['BlockInfo']['IPAddr']) {
        return false;
    }

    /** Flag for whether our checks pass or fail. */
    $Pass = false;

    /** Force domains to be an array. */
    $CIDRAM['Arrayify']($Domains);

    /** Compare the hostname against the accepted domain/hostname partials. */
    foreach ($Domains as $Domain) {
        $Len = strlen($Domain) * -1;
        if (substr($CIDRAM['Hostname'], $Len) === $Domain) {
            $Pass = true;
            break;
        }
    }

    /** Successfully passed. */
    if ($Pass) {

        /** We're only reversing; Don't forward resolve. Disable tracking and return. */
        if (!empty($Options['ReverseOnly'])) {
            if (!empty($Options['CanModTrackable'])) {
                $CIDRAM['Trackable'] = false;
            }
            return true;
        }

        /** Attempt to resolve. */
        if (!$Resolved = $CIDRAM['DNS-Resolve']($CIDRAM['Hostname'])) {
            /** Failed to resolve. Do nothing and return. */
            return false;
        }

        /** It's the real deal; Disable tracking and return. */
        if ($Resolved === $CIDRAM['BlockInfo']['IPAddr']) {
            if (!empty($Options['CanModTrackable'])) {
                $CIDRAM['Trackable'] = false;
            }
            return true;
        }

    }

    /** It's a fake; Block it. */
    $Reason = $CIDRAM['ParseVars'](['ua' => $Friendly], $CIDRAM['L10N']->getString('fake_ua'));
    $CIDRAM['BlockInfo']['ReasonMessage'] = $Reason;
    if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
        $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
    }
    $CIDRAM['BlockInfo']['WhyReason'] .= $Reason;
    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
    }
    $Debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $CIDRAM['BlockInfo']['Signatures'] .= basename($Debug['file']) . ':L' . $Debug['line'];
    $CIDRAM['BlockInfo']['SignatureCount']++;

    /** Exit. */
    return true;

};

/**
 * Checks whether an IP is expected. If so, tracking is disabled for the IP
 * being checked, and if not, the request is blocked. Has no return value.
 *
 * @param string|array $Expected Accepted/Expected IPs.
 * @param string $Friendly A friendly name to use in logfiles.
 * @param array $Options Various options that can be passed to the closure.
 */
$CIDRAM['UA-IP-Match'] = function ($Expected, $Friendly, array $Options = []) use (&$CIDRAM) {

    /** Convert expected IPs to an array. */
    $CIDRAM['Arrayify']($Expected);

    /** Compare the actual IP of the request against the expected IPs. */
    if (in_array($CIDRAM['BlockInfo']['IPAddr'], $Expected)) {
        /** Disable tracking (if there are matches, and if relevant). */
        if (!empty($Options['CanModTrackable'])) {
            $CIDRAM['Trackable'] = false;
        }
        return;
    }

    /** Nothing matched. Block it. */
    $Reason = $CIDRAM['ParseVars'](['ua' => $Friendly], $CIDRAM['L10N']->getString('fake_ua'));
    $CIDRAM['BlockInfo']['ReasonMessage'] = $Reason;
    if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
        $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
    }
    $CIDRAM['BlockInfo']['WhyReason'] .= $Reason;
    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
    }
    $Debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $CIDRAM['BlockInfo']['Signatures'] .= basename($Debug['file']) . ':L' . $Debug['line'];
    $CIDRAM['BlockInfo']['SignatureCount']++;

};

/**
 * A default closure for handling signature triggers within module files.
 *
 * @param bool $Condition Include any variable or PHP code which can be
 *      evaluated for truthiness. Truthiness is evaluated, and if true, the
 *      signature is "triggered". If false, the signature is *not* "triggered".
 * @param string $ReasonShort Cited in the "Why Blocked" field when the
 *      signature is triggered and thus included within logfile entries.
 * @param string $ReasonLong Message displayed to the user/client when blocked,
 *      to explain why they've been blocked. Optional. Defaults to the standard
 *      "Access Denied!" message.
 * @param array $DefineOptions An optional array containing key/value pairs,
 *      used to define configuration options specific to the request instance.
 *      Configuration options will be applied when the signature is triggered.
 * @return bool Returns true if the signature was triggered, and false if it
 *      wasn't. Should correspond to the truthiness of $Condition.
 */
$CIDRAM['Trigger'] = function ($Condition, $ReasonShort, $ReasonLong = '', array $DefineOptions = []) use (&$CIDRAM) {
    if (!$Condition) {
        return false;
    }
    if (!$ReasonLong) {
        $ReasonLong = $CIDRAM['L10N']->getString('denied');
    }
    if (is_array($DefineOptions) && !empty($DefineOptions)) {
        foreach ($DefineOptions as $CatKey => $CatValue) {
            if (is_array($CatValue) && !empty($CatValue)) {
                foreach ($CatValue as $OptionKey => $OptionValue) {
                    $CIDRAM['Config'][$CatKey][$OptionKey] = $OptionValue;
                }
            }
        }
    }
    $CIDRAM['BlockInfo']['ReasonMessage'] = $ReasonLong;
    if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
        $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
    }
    $CIDRAM['BlockInfo']['WhyReason'] .= $ReasonShort;
    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
    }
    $Debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $CIDRAM['BlockInfo']['Signatures'] .= basename($Debug['file']) . ':L' . $Debug['line'];
    $CIDRAM['BlockInfo']['SignatureCount']++;
    return true;
};

/**
 * A default closure for handling signature bypasses within module files.
 *
 * @param bool $Condition Include any variable or PHP code which can be
 *      evaluated for truthiness. Truthiness is evaluated, and if true, the
 *      bypass is "triggered". If false, the bypass is *not* "triggered".
 * @param string $ReasonShort Cited in the "Why Blocked" field when the
 *      bypass is triggered (included within logfile entries if there are still
 *      other preexisting signatures which have otherwise been triggered).
 * @param array $DefineOptions An optional array containing key/value pairs,
 *      used to define configuration options specific to the request instance.
 *      Configuration options will be applied when the bypass is triggered.
 * @return bool Returns true if the bypass was triggered, and false if it
 *      wasn't. Should correspond to the truthiness of $Condition.
 */
$CIDRAM['Bypass'] = function ($Condition, $ReasonShort, array $DefineOptions = []) use (&$CIDRAM) {
    if (!$Condition) {
        return false;
    }
    if (is_array($DefineOptions) && !empty($DefineOptions)) {
        foreach ($DefineOptions as $CatKey => $CatValue) {
            if (is_array($CatValue) && !empty($CatValue)) {
                foreach ($CatValue as $OptionKey => $OptionValue) {
                    $CIDRAM['Config'][$CatKey][$OptionKey] = $OptionValue;
                }
            }
        }
    }
    if (!empty($CIDRAM['BlockInfo']['WhyReason'])) {
        $CIDRAM['BlockInfo']['WhyReason'] .= ', ';
    }
    $CIDRAM['BlockInfo']['WhyReason'] .= $ReasonShort;
    if (!empty($CIDRAM['BlockInfo']['Signatures'])) {
        $CIDRAM['BlockInfo']['Signatures'] .= ', ';
    }
    $Debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $CIDRAM['BlockInfo']['Signatures'] .= basename($Debug['file']) . ':L' . $Debug['line'];
    $CIDRAM['BlockInfo']['SignatureCount']--;
    return true;
};

/**
 * Used to generate new salts when necessary, which may be occasionally used by
 * some specific optional peripheral features (note: should not be considered
 * cryptographically secure; especially so for versions of PHP < 7).
 *
 * @return string Salt.
 */
$CIDRAM['GenerateSalt'] = function () {
    static $MinLen = 32;
    static $MaxLen = 72;
    static $MinChr = 1;
    static $MaxChr = 255;
    $Salt = '';
    if (function_exists('random_int')) {
        try {
            $Length = random_int($MinLen, $MaxLen);
        } catch (\Exception $e) {
            $Length = rand($MinLen, $MaxLen);
        }
    } else {
        $Length = rand($MinLen, $MaxLen);
    }
    if (function_exists('random_bytes')) {
        try {
            $Salt = random_bytes($Length);
        } catch (\Exception $e) {
            $Salt = '';
        }
    }
    if (empty($Salt)) {
        if (function_exists('random_int')) {
            try {
                for ($Index = 0; $Index < $Length; $Index++) {
                    $Salt .= chr(random_int($MinChr, $MaxChr));
                }
            } catch (\Exception $e) {
                $Salt = '';
                for ($Index = 0; $Index < $Length; $Index++) {
                    $Salt .= chr(rand($MinChr, $MaxChr));
                }
            }
        } else {
            for ($Index = 0; $Index < $Length; $Index++) {
                $Salt .= chr(rand($MinChr, $MaxChr));
            }
        }
    }
    return $Salt;
};

/**
 * Meld together two or more strings by padding to equal length and
 * bitshifting each by each other.
 *
 * @return string The melded string.
 */
$CIDRAM['Meld'] = function () {
    $Strings = func_get_args();
    $StrLens = array_map('strlen', $Strings);
    $WalkLen = max($StrLens);
    $Count = count($Strings);
    for ($Index = 0; $Index < $Count; $Index++) {
        if ($StrLens[$Index] < $WalkLen) {
            $Strings[$Index] = str_pad($Strings[$Index], $WalkLen, "\xff");
        }
    }
    for ($Lt = $Strings[0], $Index = 1, $Meld = ''; $Index < $Count; $Index++, $Meld = '') {
        $Rt = $Strings[$Index];
        for ($Caret = 0; $Caret < $WalkLen; $Caret++) {
            $Meld .= $Lt[$Caret] ^ $Rt[$Caret];
        }
        $Lt = $Meld;
    }
    $Meld = $Lt;
    return $Meld;
};

/**
 * Clears expired entries from a list.
 *
 * @param string $List The list to clear from.
 * @param bool $Check A flag indicating when changes have occurred.
 */
$CIDRAM['ClearExpired'] = function (&$List, &$Check) use (&$CIDRAM) {
    if ($List) {
        $End = 0;
        while (true) {
            $Begin = $End;
            if (!$End = strpos($List, "\n", $Begin + 1)) {
                break;
            }
            $Line = substr($List, $Begin, $End - $Begin);
            if ($Split = strrpos($Line, ',')) {
                $Expiry = (int)substr($Line, $Split + 1);
                if ($Expiry < $CIDRAM['Now']) {
                    $List = str_replace($Line, '', $List);
                    $End = 0;
                    $Check = true;
                }
            }
        }
    }
};

/**
 * If input isn't an array, make it so. Remove empty elements.
 *
 * @param mixed $Input
 */
$CIDRAM['Arrayify'] = function (&$Input) {
    if (!is_array($Input)) {
        $Input = [$Input];
    }
    $Input = array_filter($Input);
};

/**
 * Read byte value configuration directives as byte values.
 *
 * @param string $In Input.
 * @param int $Mode Operating mode. 0 for true byte values, 1 for validating.
 *      Default is 0.
 * @return string|int Output (return type depends on operating mode).
 */
$CIDRAM['ReadBytes'] = function ($In, $Mode = 0) {
    if (preg_match('/[KMGT][oB]$/i', $In)) {
        $Unit = substr($In, -2, 1);
    } elseif (preg_match('/[KMGToB]$/i', $In)) {
        $Unit = substr($In, -1);
    }
    $Unit = isset($Unit) ? strtoupper($Unit) : 'K';
    $In = (float)$In;
    if ($Mode === 1) {
        return $Unit === 'B' || $Unit === 'o' ? $In . 'B' : $In . $Unit . 'B';
    }
    $Multiply = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];
    return (int)floor($In * (isset($Multiply[$Unit]) ? $Multiply[$Unit] : 1));
};

/**
 * Add to page output and block event logfile fields.
 *
 * @param string $FieldName Name of the field (generally, the L10N label).
 * @param string $FieldName Data for the field.
 * @param bool $Sanitise Whether the data needs to be sanitised against XSS attacks.
 */
$CIDRAM['AddField'] = function ($FieldName, $FieldData, $Sanitise = false) use (&$CIDRAM) {
    $Prepared = $Sanitise ? str_replace(
        ['<', '>', "\r", "\n"],
        ['&lt;', '&gt;', '&#13;', '&#10;'],
        $FieldData
    ) : $FieldData;
    $Logged = $CIDRAM['Config']['general']['log_sanitisation'] ? $Prepared : $FieldData;
    $CIDRAM['FieldTemplates']['Logs'] .= $FieldName . $Logged . "\n";
    $CIDRAM['FieldTemplates']['Output'][] = '<span class="textLabel">' . $FieldName . '</span>' . $Prepared . "<br />";
};

/**
 * Resolves 6to4 addresses to their IPv4 counterparts.
 *
 * @param string $In An IPv6 address.
 * @return string An IPv4 address.
 */
$CIDRAM['Resolve6to4'] = function ($In) {
    $Parts = explode(':', substr($In, 5), 8);
    if (count($Parts) < 2 || preg_match('~[^\da-f]~i', $Parts[0]) || preg_match('~[^\da-f]~i', $Parts[1])) {
        return '';
    }
    $Parts[0] = hexdec($Parts[0]) ?: 0;
    $Parts[1] = hexdec($Parts[1]) ?: 0;
    $Octets = [0 => floor($Parts[0] / 256), 1 => 0, 2 => floor($Parts[1] / 256), 3 => 0];
    $Octets[1] = $Parts[0] - ($Octets[0] * 256);
    $Octets[3] = $Parts[1] - ($Octets[2] * 256);
    return implode('.', $Octets);
};

/** Initialise cache. */
$CIDRAM['InitialiseCache'] = function () use (&$CIDRAM) {

    /** Create new cache object. */
    $CIDRAM['Cache'] = new \Maikuolan\Common\Cache();
    $CIDRAM['Cache']->EnableAPCu = $CIDRAM['Config']['supplementary_cache_options']['enable_apcu'];
    $CIDRAM['Cache']->EnableMemcached = $CIDRAM['Config']['supplementary_cache_options']['enable_memcached'];
    $CIDRAM['Cache']->EnableRedis = $CIDRAM['Config']['supplementary_cache_options']['enable_redis'];
    $CIDRAM['Cache']->EnablePDO = $CIDRAM['Config']['supplementary_cache_options']['enable_pdo'];
    $CIDRAM['Cache']->MemcachedHost = $CIDRAM['Config']['supplementary_cache_options']['memcached_host'];
    $CIDRAM['Cache']->MemcachedPort = $CIDRAM['Config']['supplementary_cache_options']['memcached_port'];
    $CIDRAM['Cache']->RedisHost = $CIDRAM['Config']['supplementary_cache_options']['redis_host'];
    $CIDRAM['Cache']->RedisPort = $CIDRAM['Config']['supplementary_cache_options']['redis_port'];
    $CIDRAM['Cache']->RedisTimeout = $CIDRAM['Config']['supplementary_cache_options']['redis_timeout'];
    $CIDRAM['Cache']->PDOdsn = $CIDRAM['Config']['supplementary_cache_options']['pdo_dsn'];
    $CIDRAM['Cache']->PDOusername = $CIDRAM['Config']['supplementary_cache_options']['pdo_username'];
    $CIDRAM['Cache']->PDOpassword = $CIDRAM['Config']['supplementary_cache_options']['pdo_password'];
    $CIDRAM['Cache']->FFDefault = $CIDRAM['Vault'] . 'cache.dat';

    if (!$CIDRAM['Cache']->connect()) {
        if ($CIDRAM['Cache']->Using === 'FF') {
            header('Content-Type: text/plain');
            die('[CIDRAM] ' . $CIDRAM['L10N']->getString('Error_WriteCache'));
        } else {
            $Status = $CIDRAM['GetStatusHTTP'](503);
            header('HTTP/1.0 503 ' . $Status);
            header('HTTP/1.1 503 ' . $Status);
            header('Status: 503 ' . $Status);
            header('Retry-After: 3600');
            die;
        }
    }

    $CIDRAM['AtCacheDestroyUnset'] = [];
    $CIDRAM['InitialiseCacheSection']('Tracking');
    $CIDRAM['InitialiseCacheSection']('DNS-Forwards');
    $CIDRAM['InitialiseCacheSection']('DNS-Reverses');

};

/**
 * Initialise a cache section.
 *
 * @param string $SectionName The name of the cache section.
 */
$CIDRAM['InitialiseCacheSection'] = function ($SectionName) use (&$CIDRAM) {

    /** Safety. */
    if (empty($SectionName) || !is_string($SectionName) || isset($CIDRAM[$SectionName], $CIDRAM[$SectionName . '-Modified'])) {
        return;
    }

    /** Mark for unsetting at cache destruction. */
    $CIDRAM['AtCacheDestroyUnset'][] = $SectionName;

    /** Section modified flag. */
    $CIDRAM[$SectionName . '-Modified'] = false;

    /** Fetch currently stored and clear expired entries. */
    if ($CIDRAM[$SectionName] = $CIDRAM['Cache']->getEntry($SectionName)) {
        if ($CIDRAM['Cache']->clearExpired($CIDRAM[$SectionName])) {
            $CIDRAM[$SectionName . '-Modified'] = true;
        }
    }

    /** Set default empty array. */
    if ($CIDRAM[$SectionName] === false) {
        $CIDRAM[$SectionName] = [];
        $CIDRAM[$SectionName . '-Modified'] = true;
    }

};

/** Destroy cache object and some related values. */
$CIDRAM['DestroyCacheObject'] = function () use (&$CIDRAM) {
    foreach ($CIDRAM['AtCacheDestroyUnset'] as $DestroyThis) {
        if ($CIDRAM[$DestroyThis . '-Modified']) {
            $CIDRAM['Cache']->setEntry($DestroyThis, $CIDRAM[$DestroyThis], 0);
        }
        unset($CIDRAM[$DestroyThis . '-Modified'], $CIDRAM[$DestroyThis]);
    }
    unset($CIDRAM['AtCacheDestroyUnset'], $CIDRAM['Cache']);
};

/**
 * Block bots masquerading as popular search engines and disable tracking for
 * for verified requests.
 */
$CIDRAM['SearchEngineVerification'] = function () use (&$CIDRAM) {
    if (
        empty($CIDRAM['TestResults']) ||
        $CIDRAM['Config']['general']['maintenance_mode'] ||
        !$CIDRAM['Config']['general']['search_engine_verification'] ||
        !empty($CIDRAM['VerificationDataReadFailure']) ||
        empty($CIDRAM['BlockInfo']['UALC'])
    ) {
        return;
    }
    if (!isset($CIDRAM['VerificationData'])) {
        if (!$Raw = $CIDRAM['ReadFile']($CIDRAM['Vault'] . 'verification.yaml')) {
            $CIDRAM['VerificationDataReadFailure'] = true;
            return;
        }
        $CIDRAM['VerificationData'] = (new \Maikuolan\Common\YAML($Raw))->Data;
    }
    if (empty($CIDRAM['VerificationData']['Search Engine Verification'])) {
        return;
    }
    foreach ($CIDRAM['VerificationData']['Search Engine Verification'] as $Name => $Values) {
        if (empty($CIDRAM[$Values['Bypass Flag']]) && (
            (!empty($Values['User Agent']) && strpos($CIDRAM['BlockInfo']['UALC'], $Values['User Agent']) !== false) ||
            (!empty($Values['User Agent Pattern']) && preg_match($Values['User Agent Pattern'], $CIDRAM['BlockInfo']['UALC']))
        )) {
            $Options = [
                'ReverseOnly' => isset($Values['Reverse Only']) ? $Values['Reverse Only'] : false,
                'CanModTrackable' => isset($Values['Can Modify Trackable']) ? $Values['Can Modify Trackable'] : true
            ];
            $CIDRAM[$Values['Closure']]($Values['Valid Domains'], $Name, $Options);
        }
    }
};

/** Reset bypass flags. */
$CIDRAM['ResetBypassFlags'] = function () use (&$CIDRAM) {
    if (isset($CIDRAM['VerificationData']['Search Engine Verification'])) {
        foreach ($CIDRAM['VerificationData']['Search Engine Verification'] as $Values) {
            if (!empty($Values['Bypass Flag'])) {
                $CIDRAM[$Values['Bypass Flag']] = false;
            }
        }
    }
};

/**
 * Block bots masquerading as popular social media tools.
 */
$CIDRAM['SocialMediaVerification'] = function () use (&$CIDRAM) {
    if (
        empty($CIDRAM['TestResults']) ||
        $CIDRAM['Config']['general']['maintenance_mode'] ||
        !$CIDRAM['Config']['general']['social_media_verification'] ||
        !empty($CIDRAM['VerificationDataReadFailure']) ||
        empty($CIDRAM['BlockInfo']['UALC'])
    ) {
        return;
    }
    if (!isset($CIDRAM['VerificationData'])) {
        if (!$Raw = $CIDRAM['ReadFile']($CIDRAM['Vault'] . 'verification.yaml')) {
            $CIDRAM['VerificationDataReadFailure'] = true;
            return;
        }
        $CIDRAM['VerificationData'] = (new \Maikuolan\Common\YAML($Raw))->Data;
    }
    if (empty($CIDRAM['VerificationData']['Social Media Verification'])) {
        return;
    }
    foreach ($CIDRAM['VerificationData']['Social Media Verification'] as $Name => $Values) {
        if (
            (!empty($Values['User Agent']) && strpos($CIDRAM['BlockInfo']['UALC'], $Values['User Agent']) !== false) ||
            (!empty($Values['User Agent Pattern']) && preg_match($Values['User Agent Pattern'], $CIDRAM['BlockInfo']['UALC']))
        ) {
            $Options = [
                'ReverseOnly' => isset($Values['Reverse Only']) ? $Values['Reverse Only'] : false,
                'CanModTrackable' => isset($Values['Can Modify Trackable']) ? $Values['Can Modify Trackable'] : true
            ];
            $CIDRAM[$Values['Closure']]($Values['Valid Domains'], $Name, $Options);
        }
    }
};

/**
 * Build directory path for logfiles.
 *
 * @param string $File The file we're building for.
 * @return bool True on success; False on failure.
 */
$CIDRAM['BuildLogPath'] = function ($File) use (&$CIDRAM) {
    $ThisPath = $CIDRAM['Vault'];
    $File = str_replace("\\", '/', $File);
    while (strpos($File, '/') !== false) {
        $Dir = substr($File, 0, strpos($File, '/'));
        $ThisPath .= $Dir . '/';
        $File = substr($File, strlen($Dir) + 1);
        if (!file_exists($ThisPath) || !is_dir($ThisPath)) {
            if (!mkdir($ThisPath)) {
                return false;
            }
        }
    }
    return true;
};

/**
 * Checks whether the specified directory is empty.
 *
 * @param string $Directory The directory to check.
 * @return bool True if empty; False if not empty.
 */
$CIDRAM['IsDirEmpty'] = function ($Directory) {
    return !((new \FilesystemIterator($Directory))->valid());
};

/**
 * Deletes empty directories (used by some front-end functions and log rotation).
 *
 * @param string $Dir The directory to delete.
 */
$CIDRAM['DeleteDirectory'] = function ($Dir) use (&$CIDRAM) {
    while (strrpos($Dir, '/') !== false || strrpos($Dir, "\\") !== false) {
        $Separator = (strrpos($Dir, '/') !== false) ? '/' : "\\";
        $Dir = substr($Dir, 0, strrpos($Dir, $Separator));
        if (!is_dir($CIDRAM['Vault'] . $Dir) || !$CIDRAM['IsDirEmpty']($CIDRAM['Vault'] . $Dir)) {
            break;
        }
        rmdir($CIDRAM['Vault'] . $Dir);
    }
};

/**
 * Convert log file configuration directives to regular expressions.
 *
 * @param string $Str The log file configuration directive to work with.
 * @param bool $GZ Whether to include GZ files in the resulting expression.
 * @return string A corresponding regular expression.
 */
$CIDRAM['BuildLogPattern'] = function ($Str, $GZ = false) {
    return '~^' . preg_replace(
        ['~\\\{(?:dd|mm|yy|hh|ii|ss)\\\}~i', '~\\\{yyyy\\\}~i', '~\\\{(?:Day|Mon)\\\}~i', '~\\\{tz\\\}~i', '~\\\{t\\\:z\\\}~i'],
        ['\d{2}', '\d{4}', '\w{3}', '.{1,2}\d{4}', '.{1,2}\d{2}\:\d{2}'],
        preg_quote(str_replace("\\", '/', $Str))
    ) . ($GZ ? '(?:\.gz)?' : '') . '$~i';
};

/**
 * GZ-compress a file (used by log rotation).
 *
 * @param string $File The file to GZ-compress.
 * @return bool True if the file exists and is readable; False otherwise.
 */
$CIDRAM['GZCompressFile'] = function ($File) {
    if (!is_file($File) || !is_readable($File)) {
        return false;
    }
    static $Blocksize = 131072;
    $Filesize = filesize($File);
    $Size = ($Filesize && $Blocksize) ? ceil($Filesize / $Blocksize) : 0;
    if ($Size > 0) {
        $Handle = fopen($File, 'rb');
        $HandleGZ = gzopen($File . '.gz', 'wb');
        $Block = 0;
        while ($Block < $Size) {
            $Data = fread($Handle, $Blocksize);
            gzwrite($HandleGZ, $Data);
            $Block++;
        }
        gzclose($HandleGZ);
        fclose($Handle);
    }
    return true;
};

/**
 * Log rotation.
 *
 * @param string $Pattern What to identify logfiles by (should be supplied via the relevant logging directive).
 * @return bool False when log rotation is disabled or errors occur; True otherwise.
 */
$CIDRAM['LogRotation'] = function ($Pattern) use (&$CIDRAM) {
    $Action = empty($CIDRAM['Config']['general']['log_rotation_action']) ? '' : $CIDRAM['Config']['general']['log_rotation_action'];
    $Limit = empty($CIDRAM['Config']['general']['log_rotation_limit']) ? 0 : $CIDRAM['Config']['general']['log_rotation_limit'];
    if (!$Limit || ($Action !== 'Delete' && $Action !== 'Archive')) {
        return false;
    }
    $Pattern = $CIDRAM['BuildLogPattern']($Pattern);
    $Arr = [];
    $Offset = strlen($CIDRAM['Vault']);
    $List = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($CIDRAM['Vault']), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($List as $Item => $List) {
        $ItemFixed = str_replace("\\", '/', substr($Item, $Offset));
        if ($ItemFixed && preg_match($Pattern, $ItemFixed) && is_readable($Item)) {
            $Arr[$ItemFixed] = filemtime($Item);
        }
    }
    unset($ItemFixed, $List, $Offset);
    $Count = count($Arr);
    $Err = 0;
    if ($Count > $Limit) {
        asort($Arr, SORT_NUMERIC);
        foreach ($Arr as $Item => $Modified) {
            if ($Action === 'Archive') {
                $Err += !$CIDRAM['GZCompressFile']($CIDRAM['Vault'] . $Item);
            }
            $Err += !unlink($CIDRAM['Vault'] . $Item);
            if (strpos($Item, '/') !== false) {
                $CIDRAM['DeleteDirectory']($Item);
            }
            $Count--;
            if (!($Count > $Limit)) {
                break;
            }
        }
    }
    return $Err ? false : true;
};

/**
 * Pseudonymise an IP address (reduce IPv4s to /24s and IPv6s to /32s).
 *
 * @param string $IP An IP address.
 * @return string A pseudonymised IP address.
 */
$CIDRAM['Pseudonymise-IP'] = function ($IP) {
    if (($CPos = strpos($IP, ':')) !== false) {
        $Parts = [(substr($IP, 0, $CPos) ?: ''), (substr($IP, $CPos +1) ?: '')];
        if (($CPos = strpos($Parts[1], ':')) !== false) {
            $Parts[1] = substr($Parts[1], 0, $CPos) ?: '';
        }
        $Parts = $Parts[0] . ':' . $Parts[1] . '::x';
        return str_replace(':::', '::', $Parts);
    }
    return preg_replace(
        '/^([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])\.([01]?\d{1,2}|2[0-4]\d|25[0-5])$/i',
        '\1.\2.\3.x',
        $IP
    );
};

/**
 * Fetch a status message from a HTTP status code for blocked requests.
 *
 * @param int $Status HTTP status code.
 * @return string HTTP status message (empty when using non-supported codes).
 */
$CIDRAM['GetStatusHTTP'] = function ($Status) {
    $Message = [
        301 => 'Moved Permanently',
        403 => 'Forbidden',
        410 => 'Gone',
        418 => 'I\'m a teapot',
        429 => 'Too Many Requests',
        451 => 'Unavailable For Legal Reasons',
        503 => 'Service Unavailable'
    ];
    return isset($Message[$Status]) ? $Message[$Status] : '';
};

/**
 * Used for matching auxiliary rule criteria.
 *
 * @param string|array $Criteria The criteria to accept for the match.
 * @param string $Actual The actual value we're trying to match.
 * @param string $Method The method for handling data when matching.
 * @return bool Match succeeded (true) or failed (false).
 */
$CIDRAM['AuxMatch'] = function ($Criteria, $Actual, $Method = '') use (&$CIDRAM) {

    /** Normalise criteria to an array. */
    if (!is_array($Criteria)) {
        $Criteria = [$Criteria];
    }

    /** Perform a match using regular expressions. */
    if ($Method === 'RegEx') {
        foreach ($Criteria as $TestCase) {
            if (preg_match($TestCase, $Actual)) {
                return true;
            }
        }
        return false;
    }

    /** Perform a match using Windows-style wildcards. */
    if ($Method === 'WinEx') {
        foreach ($Criteria as $TestCase) {
            if (preg_match('~^' . str_replace('\*', '.*', preg_quote($TestCase, '~')) . '$~', $Actual)) {
                return true;
            }
        }
        return false;
    }

    /** Perform a match using direct string comparison. */
    foreach ($Criteria as $TestCase) {
        if ($TestCase === $Actual) {
            return true;
        }
    }

    /** Failed to match anything. */
    return false;

};

/**
 * Used for performing actions when an auxiliary rule matches.
 *
 * @param string $Action The type of action to perform.
 * @param string $Name The name of the rule.
 * @param string $Reason The reason for performing the action.
 * @return bool Whether the calling parent should return immediately.
 */
$CIDRAM['AuxAction'] = function ($Action, $Name, $Reason = '') use (&$CIDRAM) {

    /** Whitelist. */
    if ($Action === 'Whitelist') {
        $CIDRAM['ZeroOutBlockInfo'](true);
        return true;
    }

    /** Greylist. */
    if ($Action === 'Greylist') {
        $CIDRAM['ZeroOutBlockInfo']();
    }

    /** Block. */
    elseif ($Action === 'Block') {
        $CIDRAM['Trigger'](true, $Name, $Reason);
    }

    /** Bypass. */
    elseif ($Action === 'Bypass') {
        $CIDRAM['Bypass'](true, $Name);
    }

    /** Don't log the request instance. */
    elseif ($Action === 'Don\'t log') {
        $CIDRAM['Flag Don\'t Log'] = true;
    }

    /** Exit. */
    return false;

};

/** Procedure for parsing and processing auxiliary rules. */
$CIDRAM['Aux'] = function () use (&$CIDRAM) {

    /** Exit procedure early if the rules don't exist. */
    if (!file_exists($CIDRAM['Vault'] . 'auxiliary.yaml')) {
        return;
    }

    /** Possibly used by some rules, but not used elsewhere. */
    if (!isset($CIDRAM['Request_Method'])) {
        $CIDRAM['Request_Method'] = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    }

    /** Potential sources. */
    static $Sources = [
        'Hostname',
        'Request_Method',
        'BlockInfo' => [
            'IPAddr',
            'IPAddrResolved',
            'Query',
            'Referrer',
            'UA',
            'UALC',
            'ReasonMessage',
            'SignatureCount',
            'Signatures',
            'WhyReason',
            'rURI'
        ]
    ];

    /** Potential modes. */
    static $Modes = ['Whitelist', 'Greylist', 'Block', 'Bypass', 'Don\'t log'];

    /** Attempt to parse the auxiliary rules file. */
    if (!isset($CIDRAM['AuxData'])) {
        $CIDRAM['AuxData'] = (new \Maikuolan\Common\YAML($CIDRAM['ReadFile']($CIDRAM['Vault'] . 'auxiliary.yaml')))->Data;
    }

    /** Iterate through the auxiliary rules. */
    foreach ($CIDRAM['AuxData'] as $Name => $Data) {

        /** Matching logic. */
        $Logic = empty($Data['Logic']) ? 'Any' : $Data['Logic'];

        /** Detailed reason. */
        $Reason = empty($Data['Reason']) ? $Name : $Data['Reason'];

        /** The matching method to use. */
        $Method = empty($Data['Method']) ? '' : $Data['Method'];

        /** Iterate through modes. */
        foreach ($Modes as $Mode) {

            /** Skip mode if not used by this rule. */
            if (empty($Data[$Mode])) {
                continue;
            }

            /** Flag for successful matches. */
            $Matched = false;

            /** Match exceptions. */
            if (!empty($Data[$Mode]['But not if matches'])) {

                /** Iterate through sources. */
                foreach ($Sources as $SourceKey => $SourceArr) {
                    if (is_array($SourceArr)) {
                        foreach ($SourceArr as $Source) {
                            if (isset(
                                $Data[$Mode]['But not if matches'][$Source],
                                $CIDRAM[$SourceKey][$Source]
                            )) {
                                if (!is_array($Data[$Mode]['But not if matches'][$Source])) {
                                    $Data[$Mode]['But not if matches'][$Source] = [$Data[$Mode]['But not if matches'][$Source]];
                                }
                                foreach ($Data[$Mode]['But not if matches'][$Source] as $Value) {
                                    /** Perform match. */
                                    if ($CIDRAM['AuxMatch']($Value, $CIDRAM[$SourceKey][$Source], $Method)) {
                                        continue 4;
                                    }
                                }
                            }
                        }
                        continue;
                    }
                    if (isset($Data[$Mode]['But not if matches'][$SourceArr], $CIDRAM[$SourceArr])) {
                        if (!is_array($Data[$Mode]['But not if matches'][$SourceArr])) {
                            $Data[$Mode]['But not if matches'][$SourceArr] = [$Data[$Mode]['But not if matches'][$SourceArr]];
                        }
                        foreach ($Data[$Mode]['But not if matches'][$SourceArr] as $Value) {
                            /** Perform match. */
                            if ($CIDRAM['AuxMatch']($Value, $CIDRAM[$SourceArr], $Method)) {
                                continue 3;
                            }
                        }
                    }
                }

            }

            /** Matches. */
            if (!empty($Data[$Mode]['If matches'])) {

                /** Iterate through sources. */
                foreach ($Sources as $SourceKey => $SourceArr) {
                    if (is_array($SourceArr)) {
                        foreach ($SourceArr as $Source) {
                            if (isset(
                                $Data[$Mode]['If matches'][$Source],
                                $CIDRAM[$SourceKey][$Source]
                            )) {
                                if (!is_array($Data[$Mode]['If matches'][$Source])) {
                                    $Data[$Mode]['If matches'][$Source] = [$Data[$Mode]['If matches'][$Source]];
                                }
                                foreach ($Data[$Mode]['If matches'][$Source] as $Value) {
                                    /** Perform match. */
                                    if ($CIDRAM['AuxMatch']($Value, $CIDRAM[$SourceKey][$Source], $Method)) {
                                        $Matched = true;
                                        if ($Logic === 'All') {
                                            continue;
                                        }
                                        if ($CIDRAM['AuxAction']($Mode, $Name, $Reason)) {
                                            return;
                                        }
                                        continue 4;
                                    } elseif ($Logic === 'All') {
                                        continue 4;
                                    }
                                }
                            }
                        }
                        continue;
                    }
                    if (isset($Data[$Mode]['If matches'][$SourceArr], $CIDRAM[$SourceArr])) {
                        if (!is_array($Data[$Mode]['If matches'][$SourceArr])) {
                            $Data[$Mode]['If matches'][$SourceArr] = [$Data[$Mode]['If matches'][$SourceArr]];
                        }
                        foreach ($Data[$Mode]['If matches'][$SourceArr] as $Value) {
                            /** Perform match. */
                            if ($CIDRAM['AuxMatch']($Value, $CIDRAM[$SourceArr], $Method)) {
                                $Matched = true;
                                if ($Logic === 'All') {
                                    continue;
                                }
                                if ($CIDRAM['AuxAction']($Mode, $Name, $Reason)) {
                                    return;
                                }
                                continue 3;
                            } elseif ($Logic === 'All') {
                                continue 3;
                            }
                        }
                    }
                }

            }

            /** Perform action for matching rules requiring all conditions to be met. */
            if ($Logic === 'All' && $Matched && $CIDRAM['AuxAction']($Mode, $Name, $Reason)) {
                return;
            }

        }

    }

};

/**
 * Write an access event to the rate limiting cache.
 *
 * @param string $RL_Capture What we've captured to identify the requesting entity.
 * @param int $RL_Size The size of the output served to the requesting entity.
 */
$CIDRAM['RL_WriteEvent'] = function ($RL_Capture, $RL_Size) use (&$CIDRAM) {
    $TimePacked = pack('l*', $CIDRAM['Now']);
    $SizePacked = pack('l*', $RL_Size);
    $Data = $TimePacked . $SizePacked . $RL_Capture;
    $Handle = fopen($CIDRAM['Vault'] . 'rl.dat', 'ab');
    fwrite($Handle, $Data);
    fclose($Handle);
};

/** Remove outdated access events from the rate limiting cache. */
$CIDRAM['RL_Clean'] = function () use (&$CIDRAM) {
    $Pos = 0;
    $EoS = strlen($CIDRAM['RL_Data']);
    while ($Pos < $EoS) {
        $Time = substr($CIDRAM['RL_Data'], $Pos, 4);
        if (strlen($Time) !== 4) {
            break;
        }
        $Time = unpack('l*', $Time);
        if ($Time[1] > $CIDRAM['RL_Expired']) {
            break;
        }
        $Pos += 8;
        $Block = substr($CIDRAM['RL_Data'], $Pos, 4);
        if (strlen($Block) !== 4) {
            $CIDRAM['RL_Data'] = '';
            break;
        }
        $Block = unpack('l*', $Block);
        $Pos += 4 + $Block[1];
    }
    if ($Pos) {
        if ($CIDRAM['RL_Data']) {
            $CIDRAM['RL_Data'] = substr($CIDRAM['RL_Data'], $Pos);
        }
        $Handle = fopen($CIDRAM['Vault'] . 'rl.dat', 'wb');
        fwrite($Handle, $CIDRAM['RL_Data']);
        fclose($Handle);
    }
};

/**
 * Count the requesting entity's requests and bandwidth usage for this period.
 *
 * @return int The requesting entity's requests and bandwidth usage for this period.
 */
$CIDRAM['RL_Get_Usage'] = function () use (&$CIDRAM) {
    $Pos = 0;
    $Bytes = 0;
    $Requests = 0;
    while (strlen($CIDRAM['RL_Data']) > $Pos && $Pos = strpos($CIDRAM['RL_Data'], $CIDRAM['RL_Capture'], $Pos + 1)) {
        if ($Pos === false) {
            break;
        }
        $Size = substr($CIDRAM['RL_Data'], $Pos - 4, 4);
        if (strlen($Size) !== 4) {
            break;
        }
        $Size = unpack('l*', $Size);
        $Bytes += $Size[1];
        $Requests++;
    }
    return ['Bytes' => $Bytes, 'Requests' => $Requests];
};

/**
 * Checks for a value within CSV.
 *
 * @param string $Value The value to look for.
 * @param string $CSV The CSV to look in.
 * @return bool True when found; False when not found.
 */
$CIDRAM['in_csv'] = function ($Value, $CSV) {
    if (!$Value || !$CSV) {
        return false;
    }
    $Arr = explode(',', $CSV);
    if (strpos($CSV, '"') !== false) {
        foreach ($Arr as &$Item) {
            if (substr($Item, 0, 1) === '"' && substr($Item, -1) === '"') {
                $Item = substr($Item, 1, -1);
            }
        }
    }
    return in_array($Value, $Arr, true);
};

/**
 * Initialises an error handler to catch any errors generated by CIDRAM when
 * needed.
 */
$CIDRAM['InitialiseErrorHandler'] = function () use (&$CIDRAM) {

    /** Stores any errors generated by the error handler. */
    $CIDRAM['Errors'] = [];

    /**
     * For a full description of all supported parameters, please see:
     * @link https://php.net/set_error_handler
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return bool True to end further processing; False to defer processing.
     */
    $CIDRAM['PreviousErrorHandler'] = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$CIDRAM) {
        $VaultLen = strlen($CIDRAM['Vault']);
        if (
            strlen($errfile) > $VaultLen &&
            str_replace("\\", '/', substr($errfile, 0, $VaultLen)) === str_replace("\\", '/', $CIDRAM['Vault'])
        ) {
            $errfile = substr($errfile, $VaultLen);
        }
        $CIDRAM['Errors'][] = [$errno, $errstr, $errfile, $errline];
        return true;
    });
};

/**
 * Restores previous error handler after having initialised an error handler.
 */
$CIDRAM['RestoreErrorHandler'] = function () use (&$CIDRAM) {

    /** Reset errors array. */
    $CIDRAM['Errors'] = [];

    /** Restore previous error handler. */
    restore_error_handler();
};

/**
 * Generates unique IDs for block events.
 *
 * @return string A unique ID to use for block events.
 */
$CIDRAM['GenerateID'] = function () {
    $Time = explode(' ', microtime(), 2);
    $Time[0] = (string)($Time[0] * 1000000);
    while (strlen($Time[0]) < 6) {
        $Time[0] = '0' . $Time[0];
    }
    if (function_exists('hrtime')) {
        try {
            $HRTime = (string)hrtime(true);
            if (strlen($HRTime) > 10) {
                $HRTime = substr($HRTime, -10);
            }
            while (strlen($HRTime) < 10) {
                $HRTime = '0' . $HRTime;
            }
        } catch (\Exception $Exception) {
            $HRTime = '';
        }
    } else {
        $HRTime = '';
    }
    $HRLen = strlen($HRTime);
    $Time = $Time[1] . '-' . $Time[0] . '-' . $HRTime;
    if ($HRLen < 10) {
        $Low = pow(10, (9 - strlen($HRTime)));
        $High = ($Low * 10) - 1;
        if (function_exists('random_int')) {
            try {
                $Pad = random_int($Low, $High);
            } catch (\Exception $Exception) {
                $Pad = rand($Low, $High);
            }
        } else {
            $Pad = rand($Low, $High);
        }
        $Time .= $Pad;
    }
    return $Time;
};
