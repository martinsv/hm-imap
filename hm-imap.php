<?php

/*  Generic PHP5 IMAP client library.

    This code is derived from the IMAP library used in Hastymail2 (www.hastymail.org)
    and is covered by the same license restrictions (GPL2)

    Hastymail is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    Hastymail is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Hastymail; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


/* base functions for IMAP communication */
class Hm_IMAP_Base {

    protected $handle = false;
    protected $debug = array();
    protected $commands = array();
    protected $responses = array();
    protected $current_command = false;
    protected $max_read = false;
    protected $command_count = 0;
    protected $config = array('server', 'starttls', 'port', 'tls', 'read_only',
        'utf7_folders', 'auth', 'search_charset', 'sort_speedup', 'folder_max');

    /**
     * increment the imap command prefix such that it counts
     * up on each command sent. ('A1', 'A2', ...)
     *
     * @return void
     */
    private function command_number() {
        $this->command_count += 1;
        return $this->command_count;
    }

    /**
     * Read IMAP literal found during parse_line().
     *
     * @param $size int size of the IMAP literal to read
     * @param $max int max size to allow
     * @param $current int current size read
     * @param $line_length int amount to read in using fgets()
     *
     * @return array the data read and any "left over" data
     *               that was inadvertantly on the same line as
     *               the last fgets result
     */
    private function read_literal($size, $max, $current, $line_length) {
        $left_over = false;
        $literal_data = $this->fgets($line_length);
        $lit_size = strlen($literal_data);
        $current += $lit_size;
        while ($lit_size < $size) {
            $chunk = $this->fgets($line_length);
            $chunk_size = strlen($chunk);
            $lit_size += $chunk_size;
            $current += $chunk_size;
            $literal_data .= $chunk;
            if ($max && $current > $max) {
                $this->max_read = true;
                break;
            }
        }
        if ($this->max_read) {
            while ($lit_size < $size) {
                $temp = $this->fgets($line_length);
                $lit_size += strlen($temp);
            }
        }
        elseif ($size < strlen($literal_data)) {
            $left_over = substr($literal_data, $size);
            $literal_data = substr($literal_data, 0, $size);
        }
        return array($literal_data, $left_over);
    }

    /**
     * IMAP message part numbers are like one half integer and one half string :) This
     * routine "increments" them correctly
     *
     * @param $part string IMAP part number
     *
     * @return string part number incremented by one
     */
    protected function update_part_num($part) {
        if (!strstr($part, '.')) {
            $part++;
        }
        else {
            $parts = explode('.', $part);
            $parts[(count($parts) - 1)]++;
            $part = implode('.', $parts);
        }
        return $part;
    }

    /**
     * break up a "line" response from imap. If we find
     * a literal we read ahead on the stream and include it.
     *
     * @param $line string data read from the IMAP server
     * @param $current_size int size of current read operation
     * @param $max int maximum input size to allow
     * @param $line_length int chunk size to read literals with
     *
     * @return array a line continuation marker and the parsed data
     *               from the IMAP server
     */
    protected function parse_line($line, $current_size, $max, $line_length) {
        /* make it a bit easier to find "atoms" */
        $line = str_replace(')(', ') (', $line);

        /* will hold the line parts */
        $parts = array();

        /* flag to control if the line continues */
        $line_cont = false;

        /* line size */
        $len = strlen($line);

        /* walk through the line */
        for ($i=0;$i<$len;$i++) {

            /* this will hold one "atom" from the parsed line */
            $chunk = '';

            /* if we hit a newline exit the loop */
            if ($line{$i} == "\r" || $line{$i} == "\n") {
                $line_cont = false;
                break;
            }

            /* skip spaces */
            if ($line{$i} == ' ') {
                continue;
            }

            /* capture special chars as "atoms" */
            elseif ($line{$i} == '*' || $line{$i} == '[' || $line{$i} == ']' || $line{$i} == '(' || $line{$i} == ')') {
                $chunk = $line{$i};
            }
        
            /* regex match a quoted string */
            elseif ($line{$i} == '"') {
                if (preg_match("/^(\"[^\"\\\]*(?:\\\.[^\"\\\]*)*\")/", substr($line, $i), $matches)) {
                    $chunk = substr($matches[1], 1, -1);
                }
                $i += strlen($chunk) + 1;
            }

            /* IMAP literal */
            elseif ($line{$i} == '{') {
                $end = strpos($line, '}');
                if ($end !== false) {
                    $literal_size  = substr($line, ($i + 1), ($end - $i - 1));
                }
                $lit_result = $this->read_literal($literal_size, $max, $current_size, $line_length);
                $chunk = $lit_result[0];
                if (!isset($lit_result[1]) || $lit_result[1] != "\r\n") {
                    $line_cont = true;
                }
                $i = $len;
            }

            /* all other atoms */
            else {
                $marker = -1;

                /* don't include these three trailing chars in the atom */
                foreach (array(' ', ')', ']') as $v) {
                    $tmp_marker = strpos($line, $v, $i);
                    if ($tmp_marker !== false && ($marker == -1 || $tmp_marker < $marker)) {
                        $marker = $tmp_marker;
                    }
                }

                /* slice out the chunk */
                if ($marker !== false && $marker !== -1) {
                    $chunk = substr($line, $i, ($marker - $i));
                    $i += strlen($chunk) - 1;
                }
                else {
                    $chunk = rtrim(substr($line, $i));
                    $i += strlen($chunk);
                }
            }

            /* if we found a worthwhile chunk add it to the results set */
            if ($chunk) {
                $parts[] = $chunk;
            }
        }
        return array($line_cont, $parts);
    }

    /**
     * wrapper around fgets using $this->handle
     *
     * @param $len int max read length for fgets
     *
     * @return string data read from the IMAP server
     */
    protected function fgets($len=false) {
        if (is_resource($this->handle) && !feof($this->handle)) {
            if ($len) {
                return fgets($this->handle, $len);
            }
            else {
                return fgets($this->handle);
            }
        }
        return '';
    }

    /**
     * loop through "lines" returned from imap and parse them with parse_line() and read_literal.
     * it can return the lines in a raw format, or parsed into atoms. It also supports a maximum
     * number of lines to return, in case we did something stupid like list a loaded unix homedir
     *
     * @param $max int max size of response allowed
     * @param $chunked bool flag to parse the data into IMAP "atoms"
     * @param $line_length chunk size to read in literals using fgets
     * @param $sort bool flag for non-compliant sort result parsing speed up
     *
     * @return array of parsed or raw results
     */
    protected function get_response($max=false, $chunked=false, $line_length=8192, $sort=false) {
        /* defaults and results containers */
        $result = array();
        $current_size = 0;
        $chunked_result = array();
        $last_line_cont = false;
        $line_cont = false;
        $c = -1;
        $n = -1;

        /* start of do -> while loop to read from the IMAP server */
        do {
            $n++;

            /* if we loose connection to the server while reading terminate */
            if (!is_resource($this->handle) || feof($this->handle)) {
                break;
            }

            /* read in a line up to 8192 bytes */
            $result[$n] = $this->fgets($line_length);

            /* keep track of how much we have read and break out if we max out. This can
             * happen on large messages. We need this check to ensure we don't exhaust available
             * memory */
            $current_size += strlen($result[$n]);
            if ($max && $current_size > $max) {
                $this->max_read = true;
                break;
            }

            /* if the line is longer than 8192 bytes keep appending more reads until we find
             * an end of line char. Keep checking the max read length as we go */
            while(substr($result[$n], -2) != "\r\n" && substr($result[$n], -1) != "\n") {
                if (!is_resource($this->handle) || feof($this->handle)) {
                    break;
                }
                $result[$n] .= $this->fgets($line_length);
                if ($result[$n] === false) {
                    break;
                }
                $current_size += strlen($result[$n]);
                if ($max && $current_size > $max) {
                    $this->max_read = true;
                    break 2;
                }
            }

            /* check line continuation marker and grab previous index and parsed chunks */
            if ($line_cont) {
                $last_line_cont = true;
                $pres = $n - 1;
                if ($chunks) {
                    $pchunk = $c;
                }
            }

            /* If we are using quick parsing of the IMAP SORT response we know the results are simply space
             * delimited UIDs so quickly explode(). Otherwise we have to follow the spec and look for quoted
             * strings and literals in the parse_line() routine. */
            if ($sort) {
                $line_cont = false;
                $chunks = explode(' ', trim($result[$n]));
            }

            /* properly parse the line */
            else {
                list($line_cont, $chunks) = $this->parse_line($result[$n], $current_size, $max, $line_length);
            }

            /* merge lines that should have been recieved as one and add to results */
            if ($chunks && !$last_line_cont) {
                $c++;
            }
            if ($last_line_cont) {
                $result[$pres] .= ' '.implode(' ', $chunks);
                if ($chunks) {
                    $line_bits = array_merge($chunked_result[$pchunk], $chunks);
                    $chunked_result[$pchunk] = $line_bits;
                }
                $last_line_cont = false;
            }

            /* add line and parsed bits to result set */
            else {
                $result[$n] = join(' ', $chunks);
                if ($chunked) {
                    $chunked_result[$c] = $chunks;
                }
            }

            /* check for untagged error condition. This represents a server problem but there is no reason
             * we can't attempt to recover with the partial response we received up until this point */
            if (substr(strtoupper($result[$n]), 0, 6) == '* BYE ') {
                break;
            }

        /* end outer loop when we receive the tagged response line */
        } while (substr($result[$n], 0, strlen('A'.$this->command_count)) != 'A'.$this->command_count);

        /* return either raw or parsed result */
        $this->responses[] = $result;
        if ($chunked) {
            $result = $chunked_result;
        }
        if ($this->current_command && isset($this->commands[$this->current_command])) {
            $start_time = $this->commands[$this->current_command];
            $this->commands[$this->current_command] = microtime(true) - $start_time;
        }
        return $result;
    }

    /**
     * put a prefix on a command and send it to the server
     *
     * @param $command string/array IMAP command
     * @param $piped bool if true builds a command set out of $command
     *
     * @return void
     */
    protected function send_command($command, $piped=false) {
        /* pipelined commands are sent in bunches. Improves performance */
        if ($piped) {
            $final_command = '';
            foreach ($command as $v) {
                $final_command .= 'A'.$this->command_number().' '.$v;
            }
            $command = $final_command;
        }

        /* single command */
        else {
            $command = 'A'.$this->command_number().' '.$command;
        }

        /* send the command out to the server */
        if (is_resource($this->handle)) {
            $res = @fputs($this->handle, $command);
            if (!$res) {
                $this->debug[] = 'Error communicating with IMAP server: '.trim($command);
            }
        }

        /* save the command and time for the IMAP debug output option */
        if (strstr($command, 'LOGIN')) {
            $command = 'LOGIN';
        }
        $this->commands[trim($command)] = microtime( true );
        $this->current_command = trim($command);
    }

    /**
     * determine if an imap response returned an "OK", returns true or false
     *
     * @param $data array parsed IMAP response
     * @param $chunked bool flag defining the type of $data
     *
     * @return bool true to indicate a success response from the IMAP server
     */
    protected function check_response($data, $chunked=false) {
        $result = false;

        /* find the last bit of the parsed response and look for the OK atom */
        if ($chunked) {
            if (!empty($data)) {
                $vals = $data[(count($data) - 1)];
                if ($vals[0] == 'A'.$this->command_count) {
                    if (strtoupper($vals[1]) == 'OK') {
                        $result = true;
                    }
                }
            }
        }

        /* pattern match the last line of a raw response */
        else {
            $line = array_pop($data);
            if (preg_match("/^A".$this->command_count." OK/i", $line)) {
                $result = true;
            }
        }
        return $result;
    }

    /**
     * convert UTF-7 encoded forlder names to UTF-8
     *
     * @param $string string mailbox name to encode
     * 
     * @return encoded mailbox
     */
    protected function utf7_decode($string) {
        if ($this->utf7_folders) {
            $string = mb_convert_encoding($string, "UTF-8", "UTF7-IMAP" );
        }
        return $string;
    }

    /**
     * convert UTF-8 encoded forlder names to UTF-7
     *
     * @param $string string mailbox name to decode
     * 
     * @return decoded mailbox
     */
    protected function utf7_encode($string) {
        if ($this->utf7_folders) {
            $string = mb_convert_encoding($string, "UTF7-IMAP", "UTF-8" );
        }
        return $string;
    }

    /**
     * type checks
     *
     * @param $val string value to check
     * @param $type string type of value to check against
     *
     * @return bool true if the type check passed
     */
    protected function input_validate($val, $type) {
        $imap_search_charsets = array(
            'UTF-8',
            'US-ASCII',
            '',
        );
        $imap_keywords = array(
            'ARRIVAL',    'DATE',    'FROM',      'SUBJECT',
            'CC',         'TO',      'SIZE',      'UNSEEN',
            'SEEN',       'FLAGGED', 'UNFLAGGED', 'ANSWERED',
            'UNANSWERED', 'DELETED', 'UNDELETED', 'TEXT',
            'ALL',
        );
        $valid = false;
        switch ($type) {
            case 'search_str':
                if (preg_match("/^[^\r\n]+$/", $val)) {
                    $valid = true;
                }
                break;
            case 'msg_part':
                if (preg_match("/^[\d\.]+$/", $val)) {
                    $valid = true;
                }
                break;
            case 'charset':
                if (!$val || in_array(strtoupper($val), $imap_search_charsets)) {
                    $valid = true;
                }
                break;
            case 'uid':
                if (preg_match("/^\d+$/", $val)) {
                    $valid = true;
                }
                break;
            case 'uid_list';
                if (preg_match("/^(\d+\s*,*\s*|(\d+|\*):(\d+|\*))+$/", $val)) {
                    $valid = true;
                }
                break;
            case 'mailbox';
                if (preg_match("/^[^\r\n]+$/", $val)) {
                    $valid = true;
                }
                break;
            case 'keyword';
                if (in_array(strtoupper($val), $imap_keywords)) {
                    $valid = true;
                }
                break;
        }
        return $valid;
    }

    /*
     * check for hacky stuff
     *
     * @param $val string value to check
     * @param $type string type the value should match
     *
     * @return bool true if the value matches the type spec
     */
    protected function is_clean($val, $type) {
        if (!$this->input_validate($val, $type)) {
            $this->debug[] = 'INVALID IMAP INPUT DETECTED: '.$type.' : '.$val;
            return false;
        }
        return true;
    }

    /**
     * overwrite defaults with supplied config array
     *
     * @param $config array associative array of configuration options
     *
     * @return void
     */
    protected function apply_config( $config ) {
        foreach($config as $key => $val) {
            if (in_array($key, $this->config)) {
                $this->{$key} = $val;
            }
        }
    }

    /**
     * attempt starttls
     *
     * @return void
     */
    protected function starttls() {
        if ($this->starttls) {
            $command = "STARTTLS\r\n";
            $this->send_command($command);
            $response = $this->get_response();
            if (!empty($response)) {
                $end = array_pop($response);
                if (substr($end, 0, strlen('A'.$this->command_count.' OK')) == 'A'.$this->command_count.' OK') {
                    stream_socket_enable_crypto($this->handle, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                }
                else {
                    $this->debug[] = 'Unexpected results from STARTTLS: '.implode(' ', $response);
                }
            }
            else {
                $this->debug[] = 'No response from STARTTLS command';
            }
        }
    }

}

/* IMAP specific parsing routines */
class Hm_IMAP_Parser extends Hm_IMAP_Base {

    /**
     * A single message part structure. This is a MIME type in the message that does NOT contain
     * any other attachments or additonal MIME types
     *
     * @param $array array low level parsed BODYSTRUCTURE response segment
     *
     * @return array strucutre representing the MIME format
     */
    protected function parse_single_part($array) {
        $vals = $array[0];
        array_shift($vals);
        array_pop($vals);
        $atts = array('name', 'filename', 'type', 'subtype', 'charset', 'id', 'description', 'encoding',
            'size', 'lines', 'md5', 'disposition', 'language', 'location', 'att_size', 'c_date', 'm_date');
        $res = array();
        if (count($vals) > 7) {
            $res['type'] = strtolower(trim(array_shift($vals)));
            $res['subtype'] = strtolower(trim(array_shift($vals)));
            if ($vals[0] == '(') {
                array_shift($vals);
                while($vals[0] != ')') {
                    if (isset($vals[0]) && isset($vals[1])) {
                        $res[strtolower($vals[0])] = $vals[1];
                        $vals = array_splice($vals, 2);
                    }
                }
                array_shift($vals);
            }
            else {
                array_shift($vals);
            }
            $res['id'] = array_shift($vals);
            $res['description'] = array_shift($vals);
            $res['encoding'] = strtolower(array_shift($vals));
            $res['size'] = array_shift($vals);
            if ($res['type'] == 'text' && isset($vals[0])) {
                $res['lines'] = array_shift($vals);
            }
            if (isset($vals[0]) && $vals[0] != ')') {
                $res['md5'] = array_shift($vals);
            }
            if (isset($vals[0]) && $vals[0] == '(') {
                array_shift($vals);
            }
            if (isset($vals[0]) && $vals[0] != ')') {
                $res['disposition'] = array_shift($vals);
                if (strtolower($res['disposition']) == 'attachment' && $vals[0] == '(') {
                    array_shift($vals);
                    $len = count($vals);
                    $flds = array('filename' => 'name', 'size' => 'att_size', 'creation-date' => 'c_date', 'modification-date' => 'm_date');
                    $index = 0;
                    for ($i=0;$i<$len;$i++) {
                        if ($vals[$i] == ')') {
                            $index = $i;
                            break;
                        }
                        if (isset($vals[$i]) && isset($flds[strtolower($vals[$i])]) && isset($vals[($i + 1)]) && $vals[($i + 1)] != ')') {
                            $res[$flds[strtolower($vals[$i])]] = $vals[($i + 1)];
                            $i++;
                        }
                    }
                    if ($index) {
                        array_splice($vals, 0, $index);
                    }
                    else {
                        array_shift($vals);
                    }
                    while ($vals[0] == ')') {
                        array_shift($vals);
                    }
                }
            }
            if (isset($vals[0])) {
                $res['language'] = array_shift($vals);
            }
            if (isset($vals[0])) {
                $res['location'] = array_shift($vals);
            }
            foreach ($atts as $v) {
                if (!isset($res[$v]) || trim(strtoupper($res[$v])) == 'NIL') {
                    $res[$v] = false;
                }
                else {
                    if ($v == 'charset') {
                        $res[$v] = strtolower(trim($res[$v]));
                    }
                    else {
                        $res[$v] = trim($res[$v]);
                    }
                }
            }
            if (!isset($res['name'])) {
                $res['name'] = 'message';
            }
        }
        return $res;
    }

    /**
     * filter out alternative mime types to simplify the end result
     *
     * @param $struct array nested array representing structure
     * @param $filter string mime type to prioritize
     * @param $parent_type string parent type to limit to
     * @param $cnt counter used in recursion
     *
     * @return $array filtered structure array excluding alternatives
     */
    protected function filter_alternatives($struct, $filter, $parent_type=false, $cnt=0) {
        $filtered = array();
        if (!is_array($struct) || empty($struct)) {
            return array($filtered, $cnt);
        }
        if (!$parent_type) {
            if (isset($struct['subtype'])) {
                $parent_type = $struct['subtype'];
            }
        }
        foreach ($struct as $index => $value) {
            if ($parent_type == 'alternative' && isset($value['subtype']) && $value['subtype'] != $filter) {
                    $cnt += 1;
                }
            else {
                $filtered[$index] = $value;
            }
            if (isset($value['subs']) && is_array($value['subs'])) {
                if (isset($struct['subtype'])) {
                    $parent_type = $struct['subtype'];
                }
                else {
                    $parent_type = false;
                }
                list($filtered[$index]['subs'], $cnt) = $this->filter_alternatives($value['subs'], $filter, $parent_type, $cnt);
            }
        }
        return array($filtered, $cnt);
    }

    /**
     * parse a multi-part mime message part
     *
     * @param $array array low level parsed BODYSTRUCTURE response segment
     * @param $part_num int IMAP message part number
     *
     * @return array structure representing the MIME format
     */
    protected function parse_multi_part($array, $part_num) {
        $struct = array();
        $index = 0;
        foreach ($array as $vals) {
            if ($vals[0] != '(') {
                break;
            }
            $type = strtolower($vals[1]);
            $sub = strtolower($vals[2]);
            $part_type = 1;
            switch ($type) {
                case 'message':
                    switch ($sub) {
                        case 'delivery-status':
                        case 'external-body':
                        case 'disposition-notification':
                        case 'rfc822-headers':
                            break;
                        default:
                            $part_type = 2;
                            break;
                    }
                    break;
            }
            if ($vals[0] == '(' && $vals[1] == '(') {
                $part_type = 3;
            }
            if ($part_type == 1) {
                $struct[$part_num] = $this->parse_single_part(array($vals));
                $part_num = $this->update_part_num($part_num);
            }
            elseif ($part_type == 2) {
                $parts = $this->split_toplevel_result($vals);
                $struct[$part_num] = $this->parse_rfc822($parts[0], $part_num);
                $part_num = $this->update_part_num($part_num);
            }
            else {
                $parts = $this->split_toplevel_result($vals);
                $struct[$part_num]['subs'] = $this->parse_multi_part($parts, $part_num.'.1');
                $part_num = $this->update_part_num($part_num);
            }
            $index++;
        }
        if (isset($array[$index][0])) {
            $struct['type'] = 'message';
            $struct['subtype'] = $array[$index][0];
        }
        return $struct;
    }
    
    /**
     * Parse a rfc822 message "container" part type
     *
     * @param $array array low level parsed BODYSTRUCTURE response segment
     * @param $part_num int IMAP message part number
     *
     * @return array strucutre representing the MIME format
     */
    protected function parse_rfc822($array, $part_num) {
        $res = array();
        array_shift($array);
        $res['type'] = strtolower(trim(array_shift($array)));
        $res['subtype'] = strtolower(trim(array_shift($array)));
        if ($array[0] == '(') {
            array_shift($array);
            while($array[0] != ')') {
                if (isset($array[0]) && isset($array[1])) {
                    $res[strtolower($array[0])] = $array[1];
                    $array = array_splice($array, 2);
                }
            }
            array_shift($array);
        }
        else {
            array_shift($array);
        }
        $res['id'] = array_shift($array);
        $res['description'] = array_shift($array);
        $res['encoding'] = strtolower(array_shift($array));
        $res['size'] = array_shift($array);
        $envelope = array();
        if ($array[0] == '(') {
            array_shift($array);
            $index = 0;
            $level = 1;
            foreach ($array as $i => $v) {
                if ($level == 0) {
                    $index = $i;
                    break;
                }
                $envelope[] = $v;
                if ($v == '(') {
                    $level++;
                }
                if ($v == ')') {
                    $level--;
                }
            }
            if ($index) {
                $array = array_splice($array, $index);
            }
        }
        $res = $this->parse_envelope($envelope, $res);
        $parts = $this->split_toplevel_result($array); 
        $res['subs'] = $this->parse_multi_part($parts, $part_num.'.1', $part_num);
        return $res;
    }

    /**
     *  helper function for parsing bodystruct responses
     *
     *  @param $array array low level parsed BODYSTRUCTURE response segment
     *
     *  @return array low level parsed data split at specific points in the result
     */
    protected function split_toplevel_result($array) {
        if (empty($array) || $array[1] != '(') {
            return array($array);
        }
        $level = 0;
        $i = 0;
        $res = array();
        foreach ($array as $val) {
            if ($val == '(') {
                $level++;
            }
            $res[$i][] = $val;
            if ($val == ')') {
                $level--;
            }
            if ($level == 1) {
                $i++;
            }
        }
        return array_splice($res, 1, -1);
    }

    /**
     * parse an envelope address
     *
     * @param $array array parsed sections from a BODYSTRUCTURE envelope address
     *
     * @return string string representation of the address
     */
    protected function parse_envelope_address($array) {
        $count = count($array) - 1;
        $string = '';
        $name = false;
        $mail = false;
        $domain = false;
        for ($i = 0;$i<$count;$i+= 6) {
            if (isset($array[$i + 1])) {
                $name = $array[$i + 1];
            }
            if (isset($array[$i + 3])) {
                $mail = $array[$i + 3];
            }
            if (isset($array[$i + 4])) {
                $domain = $array[$i + 4];
            }
            if ($name && strtoupper($name) != 'NIL') {
                $name = str_replace(array('"', "'"), '', $name);
                if ($string != '') {
                    $string .= ', ';
                }
                if ($name != $mail.'@'.$domain) {
                    $string .= '"'.$name.'" ';
                }
                if ($mail && $domain) {
                    $string .= $mail.'@'.$domain;
                }
            }
            if ($mail && $domain) {
                $string .= $mail.'@'.$domain;
            }
            $name = false;
            $mail = false;
            $domain = false;
        }
        return $string;
    }

    /**
     * parse a message envelope
     *
     * @param $array array parsed message envelope from a BODYSTRUCTURE response
     * @param $res current BODYSTRUCTURE representation
     *
     * @return array updated $res with message envelope details
     */
    protected function parse_envelope($array, $res) {
        $flds = array('date', 'subject', 'from', 'sender', 'reply-to', 'to', 'cc', 'bcc', 'in-reply-to', 'message_id');
        foreach ($flds as $val) {
            if (strtoupper($array[0]) != 'NIL') {
                if ($array[0] == '(') {
                    array_shift($array);
                    $parts = array();
                    $index = 0;
                    $level = 1;
                    foreach ($array as $i => $v) {
                        if ($level == 0) {
                            $index = $i;
                            break;
                        }
                        $parts[] = $v;
                        if ($v == '(') {
                            $level++;
                        }
                        if ($v == ')') {
                            $level--;
                        }
                    }
                    if ($index) {
                        $array = array_splice($array, $index);
                        $res[$val] = $this->parse_envelope_address($parts);
                    }
                }
                else {
                    $res[$val] = array_shift($array);
                }
            }
            else {
                $res[$val] = false;
            }
        }
        return $res;
    }

}

/* public interface to IMAP commands */
class Hm_IMAP extends Hm_IMAP_Parser {

    /* config */
    public $max_read = false;
    public $server = '127.0.0.1';
    public $starttls = false;
    public $port = 143;
    public $tls = false;
    public $read_only = false;
    public $utf7_folders = false;
    public $auth = false;
    public $search_charset = '';
    public $sort_speedup = true;
    public $folder_max = 500;

    /* internal use */
    private $state = 'unconnected';
    private $stream_size = 0;
    private $capability = false;
    private $selected_mailbox = false;

    /**
     * constructor
     */
    public function __construct() {
    }

    /**
     * fetch IMAP server capability response
     *
     * @return string capability response
     */
    public function get_capability() {
        if ( $this->capability ) {
            return $this->capability;
        }
        else {
            $command = "CAPABILITY\r\n";
            $this->send_command($command);
            $response = $this->get_response();
            $this->capability = implode(' ', $response);
            return $this->capability;
        }
    }

    /**
     * output IMAP session debug info
     *
     * @param $full bool flag to enable full IMAP response display
     *
     * @return void
     */
    public function debug($full=false) {
        printf("\nDebug %s\n", print_r(array_merge($this->debug, $this->commands), true));
        if ($full) {
            printf("Response %s", print_r($this->responses, true));
        }
    }

    /**
     * get unseen message UIDS for a mailbox
     *
     * @return array list of IMAP message uids
     */
    public function get_unread_messages() {
        $command = "UID SEARCH (UNSEEN) ALL\r\n";
        $this->send_command($command);
        $res = $this->get_response(false, true);
        $status = $this->check_response($res, true);
        $uids = array();
        if ($status) {
            array_pop($res);
            foreach ($res as $vals) {
                foreach ($vals as $v) {
                    if (intval($v)) {
                        $uids[] = $v;
                    }
                }
            }
        }
        return $uids;
    }

    /**
     * select a mailbox
     *
     * @param $mailbox string the mailbox to attempt to select
     *
     * @return array list of information about the selected mailbox
     */
    public function select_mailbox($mailbox) {
        $box = $this->utf7_encode(str_replace('"', '\"', $mailbox));
        if (!$this->is_clean($box, 'mailbox')) {
            return false;
        }
        if (!$this->read_only) {
            $command = "SELECT \"$box\"\r\n";
        }
        else {
            $command = "EXAMINE \"$box\"\r\n";
        }
        $this->send_command($command);
        $res = $this->get_response(false, true);
        $status = $this->check_response($res, true);
        $uidvalidity = 0;
        $exists = 0;
        $unseen = 0;
        $uidnext = 0; 
        $flags = array();
        $pflags = array();
        foreach ($res as $vals) {
            if (in_array('UIDNEXT', $vals)) {
                foreach ($vals as $i => $v) {
                    if (intval($v) && isset($vals[($i - 1)]) && $vals[($i - 1)] == 'UIDNEXT') {
                        $uidnext = $v;
                    }
                }
            }
            if (in_array('UNSEEN', $vals)) {
                foreach ($vals as $i => $v) {
                    if (intval($v) && isset($vals[($i - 1)]) && $vals[($i - 1)] == 'UNSEEN') {
                        $unseen = $v;
                    }
                }
            }
            if (in_array('UIDVALIDITY', $vals)) {
                foreach ($vals as $i => $v) {
                    if (intval($v) && isset($vals[($i - 1)]) && $vals[($i - 1)] == 'UIDVALIDITY') {
                        $uidvalidity = $v;
                    }
                }
            }
            if (in_array('PERMANENTFLAGS', $vals)) {
                $collect_flags = false;
                foreach ($vals as $i => $v) {
                    if ($v == ')') {
                        $collect_flags = false;
                    }
                    if ($collect_flags) {
                        $pflags[] = $v;
                    }
                    if ($v == '(') {
                        $collect_flags = true;
                    }
                }
            }
            if (in_array('FLAGS', $vals)) {
                $collect_flags = false;
                foreach ($vals as $i => $v) {
                    if ($v == ')') {
                        $collect_flags = false;
                    }
                    if ($collect_flags) {
                        $flags[] = $v;
                    }
                    if ($v == '(') {
                        $collect_flags = true;
                    }
                }
            }
            if (in_array('EXISTS', $vals)) {
                foreach ($vals as $i => $v) {
                    if (intval($v) && isset($vals[($i + 1)]) && $vals[($i + 1)] == 'EXISTS') {
                        $exists = $v;
                    }
                }
            }
        }
        if ($status) {
            $this->state = 'selected';
            $this->selected_mailbox = $box;
        }
        return array(
            'selected' => $status,
            'uidvalidity' => $uidvalidity,
            'exists' => $exists,
            'first_unseen' => $unseen,
            'uidnext' => $uidnext,
            'flags' => $flags,
            'permanentflags' => $pflags
        );
    }

    /**
     * authenticate the username/password
     *
     * @param $username IMAP login name
     * @param $password IMAP password
     *
     * @return bool true on sucessful login
     */
    public function authenticate($username, $password) {
        $this->starttls();
        switch (strtolower($this->auth)) {
            case 'cram-md5':
                $this->banner = $this->fgets(1024);
                $cram1 = 'A'.$this->command_number().' AUTHENTICATE CRAM-MD5'."\r\n";
                fputs ($this->handle, $cram1);
                $response = $this->fgets(1024);
                $this->responses[] = $response;
                $challenge = base64_decode(substr(trim($response), 1));
                $pass .= str_repeat(chr(0x00), (64-strlen($password)));
                $ipad = str_repeat(chr(0x36), 64);
                $opad = str_repeat(chr(0x5c), 64);
                $digest = bin2hex(pack("H*", md5(($pass ^ $opad).pack("H*", md5(($pass ^ $ipad).$challenge)))));
                $challenge_response = base64_encode($username.' '.$digest);
                fputs($this->handle, $challenge_response."\r\n");
                break;
            default:
                $login = 'LOGIN "'.str_replace('"', '\"', $username).'" "'.str_replace('"', '\"', $password). "\"\r\n";
                $this->send_command($login);
                break;
        }
        $res = $this->get_response();
        $authed = false;
        if (is_array($res) && !empty($res)) {
            $response = array_pop($res);
            if (!$this->auth) {
                if (isset($res[1])) {
                    $this->banner = $res[1];
                }
                if (isset($res[0])) {
                    $this->banner = $res[0];
                }
            }
            if (stristr($response, 'A'.$this->command_count.' OK')) {
                $authed = true;
                $this->state = 'authenticated';
            }
        }
        if ( $authed ) {
            $this->debug[] = 'Logged in successfully as '.$username;
            $this->get_capability();
        }
        else {
            $this->debug[] = 'Log in for '.$username.' FAILED';
        }
        return $authed;
    }

    /**
     * connect to the imap server
     *
     * @param $config array list of configuration options for this connections
     *
     * @return bool true on connection sucess
     */
    public function connect( $config ) {
        if (isset($config['username']) && isset($config['password'])) {
            $this->commands = array();
            $this->debug = array();
            $this->capability = false;
            $this->responses = array();
            $this->current_command = false;
            $this->apply_config($config);
            if ($this->tls) {
                $this->server = 'tls://'.$this->server;
            } 
            $this->debug[] = 'Connecting to '.$this->server.' on port '.$this->port;
            $this->handle = @fsockopen($this->server, $this->port, $errorno, $errorstr, 30);
            if (is_resource($this->handle)) {
                $this->debug[] = 'Successfully opened port to the IMAP server';
                $this->state = 'connected';
                return $this->authenticate($config['username'], $config['password']);
            }
            else {
                $this->debug[] = 'Could not connect to the IMAP server';
                $this->debug[] = 'fsockopen errors #'.$errorno.'. '.$errorstr;
                return false;
            }
        }
        else {
            $this->debug[] = 'username and password must be set in the connect() config argument';
            return false;
        }
    }

    /**
     * close the IMAP connection
     *
     * @return void
     */
    public function disconnect() {
        $command = "LOGOUT\r\n";
        $this->state = 'unconnected';
        $this->selected_mailbox = false;
        $this->send_command($command);
        $result = $this->get_response();
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * return a header list for the supplied message uids
     *
     * @param $uids array/string an array of uids or a valid IMAP sequence set as a string
     *
     * @return array list of headers and values for the specified uids
     */
    public function get_message_list($uids) {
        if (is_array($uids)) {
            sort($uids);
            $sorted_string = implode(',', $uids);
        }
        else {
            $sorted_string = $uids;
        }
        if (!$this->is_clean($sorted_string, 'uid_list')) {
            return array();
        }
        $command = 'UID FETCH '.$sorted_string.' (FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (SUBJECT FROM '.
                   "DATE CONTENT-TYPE X-PRIORITY TO)])\r\n";
        $this->send_command($command);
        $res = $this->get_response(false, true);
        $status = $this->check_response($res, true);
        $tags = array('UID' => 'uid', 'FLAGS' => 'flags', 'RFC822.SIZE' => 'size', 'INTERNALDATE' => 'internal_date');
        $junk = array('SUBJECT', 'FROM', 'CONTENT-TYPE', 'TO', '(', ')', ']', 'X-PRIORITY', 'DATE');
        $flds = array('date' => 'date', 'from' => 'from', 'to' => 'to', 'subject' => 'subject', 'content-type' => 'content_type', 'x-priority' => 'x_priority');
        $headers = array();
        foreach ($res as $n => $vals) {
            if (isset($vals[0]) && $vals[0] == '*') {
                $uid = 0;
                $size = 0;
                $subject = '';
                $from = '';
                $date = '';
                $x_priority = 0;
                $content_type = '';
                $to = '';
                $flags = '';
                $internal_date = '';
                $count = count($vals);
                for ($i=0;$i<$count;$i++) {
                    if ($vals[$i] == 'BODY[HEADER.FIELDS') {
                        $i++;
                        while(isset($vals[$i]) && in_array(strtoupper($vals[$i]), $junk)) {
                            $i++;
                        }
                        $last_header = false;
                        $lines = explode("\r\n", $vals[$i]);
                        foreach ($lines as $line) {
                            $header = strtolower(substr($line, 0, strpos($line, ':')));
                            if (!$header || (!isset($flds[$header]) && $last_header)) {
                                ${$flds[$last_header]} .= "\r\n".$line;
                            }
                            elseif (isset($flds[$header])) {
                                ${$flds[$header]} = substr($line, (strpos($line, ':') + 1));
                                $last_header = $header;
                            }
                        }
                    }
                    elseif (isset($tags[strtoupper($vals[$i])])) {
                        if (isset($vals[($i + 1)])) {
                            if ($tags[strtoupper($vals[$i])] == 'flags' && $vals[$i + 1] == '(') {
                                $n = 2;
                                while (isset($vals[$i + $n]) && $vals[$i + $n] != ')') {
                                    $flags .= ' '.$vals[$i + $n];
                                    $n++;
                                }
                                $i += $n;
                            }
                            else {
                                $$tags[strtoupper($vals[$i])] = $vals[($i + 1)];
                                $i++;
                            }
                        }
                    }
                }
                if ($uid) {
                    $cset = '';
                    if (stristr($content_type, 'charset=')) {
                        if (preg_match("/charset\=([^\s;]+)/", $content_type, $matches)) {
                            $cset = trim(strtolower(str_replace(array('"', "'"), '', $matches[1])));
                        }
                    }
                    $headers[(string) $uid] = array('uid' => $uid, 'flags' => $flags, 'internal_date' => $internal_date, 'size' => $size,
                                     'date' => $date, 'from' => $from, 'to' => $to, 'subject' => $subject, 'content-type' => $content_type,
                                     'timestamp' => time(), 'charset' => $cset, 'x-priority' => $x_priority);

                    $headers[$uid] = array_map('trim', $headers[$uid]);
                }
            }
        }
        return $headers;
    }
    /**
     * get the IMAP BODYSTRUCTURE of a message
     *
     * @param $uid int IMAP UID of the message
     * @param $filter string alternative MIME message format to prioritize
     *
     * @return array message structure represented as a nested array
     */
    public function get_message_structure($uid, $filter=false) {
        if (!$this->is_clean($uid, 'uid')) {
            return array();
        }
        $part_num = 1;
        $struct = array();
        $command = "UID FETCH $uid BODYSTRUCTURE\r\n";
        $this->send_command($command);
        $result = $this->get_response(false, true);
        while (isset($result[0][0]) && isset($result[0][1]) && $result[0][0] == '*' && strtoupper($result[0][1]) == 'OK') {
            array_shift($result);
        }
        $status = $this->check_response($result, true);
        $response = array();
        if (!isset($result[0][4])) {
            $status = false;
        }
        if ($status) {
            if (strtoupper($result[0][4]) == 'UID')  {
                $response = array_slice($result[0], 7, -1);
            }
            else {
                $response = array_slice($result[0], 5, -1);
            }
            $response = $this->split_toplevel_result($response);
            if (count($response) > 1) {
                $struct = $this->parse_multi_part($response, 1, 1);
            }
            else {
                $struct[1] = $this->parse_single_part($response);
            }
        } 
        if ($filter) {
            return $this->filter_alternatives($struct, $filter);
        }
        return $struct;
    }

    /**
     * get a message content
     *
     * @param $uid int a single IMAP message UID
     * @param $message_part string the IMAP message part number
     * @param $raw bool flag to enabled fetching the entire message as text
     * @param $max int maximum read length to allow
     *
     * @return string message content
     */
    public function get_message_content($uid, $message_part, $raw=false, $max=false) {
        if (!$this->is_clean($uid, 'uid')) {
            return '';
        }
        if ($raw) {
            $command = "UID FETCH $uid BODY[]\r\n";
        }
        else {
            if (!$this->is_clean($message_part, 'msg_part')) {
                return '';
            }
            $command = "UID FETCH $uid BODY[$message_part]\r\n";
        }
        $this->send_command($command);
        $result = $this->get_response($max, true);
        $status = $this->check_response($result, true);
        $res = '';
        foreach ($result as $vals) {
            if ($vals[0] != '*') {
                continue;
            }
            $search = true;
            foreach ($vals as $v) {
                if ($v != ']' && !$search) {
                    if ($v == 'NIL') {
                        $res = '';
                        break 2;
                    }
                    $res = trim(preg_replace("/\s*\)$/", '', $v));
                    break 2;
                }
                if (stristr(strtoupper($v), 'BODY')) {
                    $search = false;
                }
            }
        }
        return $res;
    }

    /**
     * search a field for a keyword
     *
     * @param $fld string field to search
     * @param $uids array/string an array of uids or a valid IMAP sequence set as a string
     * @param $term string search term
     *
     * @return array list of IMAP message UIDs that match the search
     */
    public function search($fld, $uids, $term) {
        if (!$this->is_clean($fld, 'search_str') || !$this->is_clean($this->search_charset, 'charset') || !$this->is_clean($term, 'search_str')) {
            return array();
        }
        if (!empty($uids)) {
            if (is_array($uids)) {
                $uids = implode(',', $uids);
            }
            if (!$this->is_clean($uids, 'uid_list')) {
                return array();
            }
            $uids = 'UID '.$uids;
        }
        else {
            $uids = 'ALL';
        }
        if ($this->search_charset) {
            $charset = 'CHARSET '.strtoupper($this->search_charset).' ';
        }
        else {
            $charset = '';
        }
        $command = 'UID SEARCH '.$charset.$uids.' '.$fld.' "'.str_replace('"', '\"', $term)."\"\r\n";
        $this->send_command($command);
        $result = $this->get_response(false, true);
        $status = $this->check_response($result, true);
        $res = array();
        if ($status) {
            array_pop($result);
            foreach ($result as $vals) {
                foreach ($vals as $v) {
                    if (preg_match("/^\d+$/", $v)) {
                        $res[] = $v;
                    }
                }
            }
        }
        return $res;
    }

    /**
     * get the headers for the selected message
     *
     * @param $uid int IMAP message UID
     * @param $message_part string IMAP message part number
     *
     * @return array associate array of message headers
     */
    public function get_message_headers($uid, $message_part) {
        if (!$this->is_clean($uid, 'uid')) {
            return array();
        }
        if ($message_part == 1 || !$message_part) {
            $command = "UID FETCH $uid (FLAGS BODY[HEADER])\r\n";
        }
        else {
            if (!$this->is_clean($message_part, 'msg_part')) {
                return array();
            }
            $command = "UID FETCH $uid (FLAGS BODY[$message_part.HEADER])\r\n";
        }
        $this->send_command($command);
        $result = $this->get_response(false, true);
        $status = $this->check_response($result, true);
        $headers = array();
        $flags = array();
        if ($status) {
            foreach ($result as $vals) {
                if ($vals[0] != '*') {
                    continue;
                }
                $search = true;
                $flag_search = false;
                foreach ($vals as $v) {
                    if ($flag_search) {
                        if ($v == ')') {
                            $flag_search = false;
                        }
                        elseif ($v == '(') {
                            continue;
                        }
                        else {
                            $flags[] = $v;
                        }
                    }
                    elseif ($v != ']' && !$search) {
                        $parts = explode("\r\n", $v);
                        if (is_array($parts) && !empty($parts)) {
                            $i = 0;
                            foreach ($parts as $line) {
                                $split = strpos($line, ':');
                                if (preg_match("/^from /i", $line)) {
                                    continue;
                                }
                                if (isset($headers[$i]) && trim($line) && ($line{0} == "\t" || $line{0} == ' ')) {
                                    $headers[$i][1] .= ' '.trim($line);
                                }
                                elseif ($split) {
                                    $i++;
                                    $last = substr($line, 0, $split);
                                    $headers[$i] = array($last, trim(substr($line, ($split + 1))));
                                }
                            }
                        }
                        break;
                    }
                    if (stristr(strtoupper($v), 'BODY')) {
                        $search = false;
                    }
                    elseif (stristr(strtoupper($v), 'FLAGS')) {
                        $flag_search = true;
                    }
                }
            }
            if (!empty($flags)) {
                $headers[] = array('Flags', join(' ', $flags));
            }
        }
        $results = array();
        foreach ($headers as $vals) {
            $results[$vals[0]] = $vals[1];
        }
        return $results;
    }

    /**
     * use the SORT extension to get a sorted UID list
     *
     * @param $sort string sort order. can be one of ARRIVAL, DATE, CC, TO, SUBJECT, FROM, or SIZE
     * @param $reverse bool flag to reverse the sort order
     * @param $filter string can be one of ALL, SEEN, UNSEEN ANSWERED, UNANSWERED, DELETED, UNDELETED, FLAGGED, or UNFLAGGED
     *
     * @return array list of IMAP message UIDs
     */
    public function get_message_uids($sort='ARRIVAL', $reverse=true, $filter='ALL') {
        if (!$this->is_clean($sort, 'keyword') || !$this->is_clean($filter, 'keyword')) {
            return false;
        }
        $command = 'UID SORT ('.$sort.') US-ASCII '.$filter."\r\n";
        $this->send_command($command);
        if ($this->sort_speedup) {
            $speedup = true;
        }
        else {
            $speedup = false;
        }
        $res = $this->get_response(false, true, 8192, $speedup);
        $status = $this->check_response($res, true);
        $uids = array();
        foreach ($res as $vals) {
            if ($vals[0] == '*' && strtoupper($vals[1]) == 'SORT') {
                array_shift($vals);
                array_shift($vals);
                $uids = array_merge($uids, $vals);
            }
            else {
                if (preg_match("/^(\d)+$/", $vals[0])) {
                    $uids = array_merge($uids, $vals);
                }
            }
        }
        if ($reverse) {
            $uids = array_reverse($uids);
        }
        return $uids;
    }

    /**
     * get a list of mailbox folders
     *
     * @param $lsub bool flag to limit results to subscribed folders only
     *
     * @return array associative array of folder details
     */
    public function get_mailbox_list($lsub=false) {
        /* possibly limit list response to subscribed folders only */
        if ($lsub) {
            $imap_command = 'LSUB';
        }
        else {
            $imap_command = 'LIST';
        }

        /* defaults */
        $folders = array();
        $excluded = array();
        $parents = array();
        $delim = false;

        /* loop through namespaces to issue the IMAP LIST/LSUB command against */
        foreach ($this->get_namespaces() as $nsvals) {

            /* build IMAP command */
            $namespace = $nsvals['prefix'];
            $delim = $nsvals['delim'];
            $ns_class = $nsvals['class'];
            if (strtoupper($namespace) == 'INBOX') { 
                $namespace = '';
            }

            /* send command to the IMAP server and fetch the response */
            $command = $imap_command.' "'.$namespace."\" \"*\"\r\n";
            $this->send_command($command);
            $result = $this->get_response($this->folder_max, true);

            /* loop through the "parsed" response. Each iteration is one folder */
            foreach ($result as $vals) {

                /* break at the end of the list */
                if (!isset($vals[0]) || $vals[0] == 'A'.$this->command_count) {
                    continue;
                }

                /* defaults */
                $flags = false;
                $flag = false;
                $delim_flag = false;
                $parent = '';
                $base_name = '';
                $folder_parts = array();
                $no_select = false;
                $can_have_kids = true;
                $has_kids = false;
                $marked = false;
                $folder_sort_by = 'ARRIVAL';
                $check_for_new = false;

                /* full folder name, includes an absolute path of parent folders */
                $folder = $this->utf7_decode($vals[(count($vals) - 1)]);

                /* sometimes LIST responses have dupes */
                if (isset($folders[$folder]) || !$folder) {
                    continue;
                }

                /* folder flags */
                foreach ($vals as $v) {
                    if ($v == '(') {
                        $flag = true;
                    }
                    elseif ($v == ')') {
                        $flag = false;
                        $delim_flag = true;
                    }
                    else {
                        if ($flag) {
                            $flags .= ' '.$v;
                        }
                        if ($delim_flag && !$delim) {
                            $delim = $v;
                            $delim_flag = false;
                        }
                    }
                }

                /* get each folder name part of the complete hierarchy */
                $folder_parts = array();
                if ($delim && strstr($folder, $delim)) {
                    $temp_parts = explode($delim, $folder);
                    foreach ($temp_parts as $g) {
                        if (trim($g)) {
                            $folder_parts[] = $g;
                        }
                    }
                }
                else {
                    $folder_parts[] = $folder;
                }

                /* get the basename part of the folder name. For a folder named "inbox.sent.march"
                 * with a delimiter of "." the basename would be "march" */
                if (isset($folder_parts[(count($folder_parts) - 1)])) {
                    $base_name = $folder_parts[(count($folder_parts) - 1)];
                }
                else {
                    $base_name = $folder;
                }

                /* determine the parent folder basename if it exists */
                if (isset($folder_parts[(count($folder_parts) - 2)])) {
                    $parent = join($delim, array_slice($folder_parts, 0, -1));
                    if ($parent.$delim == $namespace) {
                        $parent = '';
                    }
                }

                /* build properties from the flags string */
                if (stristr($flags, 'marked')) { 
                    $marked = true;
                }
                if (stristr($flags, 'noinferiors')) { 
                    $can_have_kids = false;
                }
                if (($folder == $namespace && $namespace) || stristr($flags, 'haschildren')) { 
                    $has_kids = true;
                }
                if ($folder != 'INBOX' && $folder != $namespace && stristr($flags, 'noselect')) { 
                    $no_select = true;
                }

                /* store the results in the big folder list struct */
                $folders[$folder] = array('parent' => $parent, 'delim' => $delim, 'name' => $folder,
                                        'name_parts' => $folder_parts, 'basename' => $base_name,
                                        'realname' => $folder, 'namespace' => $namespace, 'marked' => $marked,
                                        'noselect' => $no_select, 'can_have_kids' => $can_have_kids,
                                        'has_kids' => $has_kids);

                /* store a parent list used below */
                if ($parent && !in_array($parent, $parents)) {
                    $parents[$parent][] = $folders[$folder];
                }
            }
        }

        /* attempt to fix broken hierarchy issues. If a parent folder was not found fabricate
         * it in the folder list */
        $place_holders = array();
        foreach ($parents as $val => $parent_list) {
            foreach ($parent_list as $parent) {
                $found = false;
                foreach ($folders as $i => $vals) {
                    if ($vals['name'] == $val) {
                        $folders[$i]['has_kids'] = 1;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if (count($parent['name_parts']) > 1) {
                        foreach ($parent['name_parts'] as $i => $v) {
                            $fname = join($delim, array_slice($parent['name_parts'], 0, ($i + 1)));
                            $name_parts = array_slice($parent['name_parts'], 0, ($i + 1));
                            if (!isset($folders[$fname])) {
                                $freal = $v;
                                if ($i > 0) {
                                    $fparent = join($delim, array_slice($parent['name_parts'], 0, $i));
                                }
                                else {
                                    $fparent = false;
                                }
                                $place_holders[] = $fname;
                                $folders[$fname] = array('parent' => $fparent, 'delim' => $delim, 'name' => $freal,
                                    'name_parts' => $name_parts, 'basename' => $freal, 'realname' => $fname,
                                    'namespace' => $namespace, 'marked' => false, 'noselect' => true,
                                    'can_have_kids' => true, 'has_kids' => true);
                            }
                        }
                    }
                }
            }
        }

        /* ALL account need an inbox. If we did not find one manually add it to the results */
        if (!isset($folders['INBOX'])) {
            $folders = array_merge(array('INBOX' => array(
                    'name' => 'INBOX', 'basename' => 'INBOX', 'realname' => 'INBOX', 'noselect' => false,
                    'parent' => false, 'has_kids' => false, )), $folders);
        }

        /* sort and return the list */
        ksort($folders);
        return $folders;
    }

    /**
     * get IMAP folder namespaces
     *
     * @return array list of available namespace details
     */
    public function get_namespaces() {
        $data = array();
        $this->send_command("NAMESPACE\r\n");
        $res = $this->get_response();
        $this->namespace_count = 0;
        if ($this->check_response($res)) {
            if (preg_match("/\* namespace (\(.+\)|NIL) (\(.+\)|NIL) (\(.+\)|NIL)/i", $res[0], $matches)) {
                $classes = array(1 => 'personal', 2 => 'other_users', 3 => 'shared');
                foreach ($classes as $i => $v) {
                    if (trim(strtoupper($matches[$i])) == 'NIL') {
                        continue;
                    }
                    $list = str_replace(') (', '),(', substr($matches[$i], 1, -1));
                    $prefix = '';
                    $delim = '';
                    foreach (explode(',', $list) as $val) {
                        $val = trim($val, ")(\r\n ");
                        if (strlen($val) == 1) {
                            $delim = $val;
                            $prefix = '';
                        }
                        else {
                            $delim = substr($val, -1);
                            $prefix = trim(substr($val, 0, -1));
                        }
                        $this->namespace_count++;
                        $data[] = array('delim' => $delim, 'prefix' => $prefix, 'class' => $v);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * start streaming a message part. returns the number of characters in the message
     *
     * @param $uid int IMAP message UID
     * @param $message_part string IMAP message part number
     *
     * @return int the size of the message queued up to stream
     */
    public function start_message_stream($uid, $message_part) {
        if (!$this->is_clean($uid, 'uid')) {
            return false;
        }
        if ($message_part == 0) {
            $command = "UID FETCH $uid BODY[]\r\n";
        }
        else {
            if (!$this->is_clean($message_part, 'msg_part')) {
                return false;
            }
            $command = "UID FETCH $uid BODY[$message_part]\r\n";
        }
        $this->send_command($command);
        $result = $this->fgets(1024);
        $size = false;
        if (preg_match("/\{(\d+)\}\r\n/", $result, $matches)) {
            $size = $matches[1];
            $this->stream_size = $size;
            $this->current_stream_size = 0;
        }
        return $size;
    }

    /**
     * read a line from a message stream. Called until it returns
     * false will "stream" a message part content one line at a time.
     * useful for avoiding memory consumption when dealing with large
     * attachments
     *
     * @param $size int chunk size to read using fgets
     *
     * @return string chunk of the streamed message
     */
    public function read_stream_line($size=1024) {
        if ($this->stream_size) {
            $res = $this->fgets(1024);
            while(substr($res, -2) != "\r\n") {
                $res .= $this->fgets($size);
            }
            if ($this->check_response(array($res))) {
                $res = false;
            }
            if ($res) {
                $this->current_stream_size += strlen($res);
            }
            if ($this->current_stream_size >= $this->stream_size) {
                $this->stream_size = 0;
                $res = '';
            }
        }
        else {
            $res = false;
        }
        return $res;
    }

    /**
     * delete an existing mailbox
     *
     * @param $mailbox string IMAP mailbox name to delete
     * 
     * @return bool tru if the mailbox was deleted
     */
    public function delete_mailbox($mailbox) {
        if (!$this->is_clean($mailbox, 'mailbox')) {
            return false;
        }
        if ($this->read_only) {
            $this->debug[] = 'Delete mailbox not permitted in read only mode';
            return false;
        }
        $command = 'DELETE "'.str_replace('"', '\"', $this->utf7_encode($mailbox))."\"\r\n";
        $this->send_command($command);
        $result = $this->get_response(false);
        $status = $this->check_response($result, false);
        if ($status) {
            return true;
        }
        else {
            $this->debug[] = str_replace('A'.$this->command_count, '', $result[0]);
            return false;
        }
    }

    /**
     * rename and existing mailbox
     *
     * @param $mailbox string IMAP mailbox to rename
     * @param $new_mailbox string new name for the mailbox
     *
     * @return bool true if the rename operation worked
     */
    public function rename_mailbox($mailbox, $new_mailbox) {
        if (!$this->is_clean($mailbox, 'mailbox') || !$this->is_clean($new_mailbox, 'mailbox')) {
            return false;
        }
        if ($this->read_only) {
            $this->debug[] = 'Rename mailbox not permitted in read only mode';
            return false;
        }
        $command = 'RENAME "'.$this->utf7_encode($mailbox).'" "'.$this->utf7_encode($new_mailbox).'"'."\r\n";
        $this->send_command($command);
        $result = $this->get_response(false);
        $status = $this->check_response($result, false);
        if ($status) {
            return true;
        }
        else {
            $this->debug[] = str_replace('A'.$this->command_count, '', $result[0]);
            return false;
        }
    } 

    /**
     * create a new mailbox
     *
     * @param $mailbox string IMAP mailbox name
     *
     * @return bool true if the mailbox was created
     */
    public function create_mailbox($mailbox) {
        if (!$this->is_clean($mailbox, 'mailbox')) {
            return false;
        }
        if ($this->read_only) {
            $this->debug[] = 'Create mailbox not permitted in read only mode';
            return false;
        }
        $command = 'CREATE "'.$this->utf7_encode($mailbox).'"'."\r\n";
        $this->send_command($command);
        $result = $this->get_response(false);
        $status = $this->check_response($result, false);
        if ($status) {
            return true;
        }
        else {
            $this->debug[] =  str_replace('A'.$this->command_count, '', $result[0]);
            return false;
        }
    }

    /**
     * perform an IMAP action on a message
     *
     * @param $action string action to perform, can be one of READ, UNREAD, FLAG,
     *                       UNFLAG, ANSWERED, DELETE, UNDELETE, EXPUNGE, or COPY
     * @param $uids array/string an array of uids or a valid IMAP sequence set as a string
     * @param $mailbox string destination IMAP mailbox name for operations the require one
     */
    public function message_action($action, $uids, $mailbox=false) {
        $keepers = array();
        $uid_strings = array();
        if (is_array($uids)) {
            if (count($uids) > 1000) {
                while (count($uids) > 1000) { 
                    $uid_strings[] = implode(',', array_splice($uids, 0, 1000));
                }
                if (count($uids)) {
                    $uid_strings[] = implode(',', $uids);
                }
            }
            else {
                $uid_strings[] = implode(',', $uids);
            }
        }
        else {
            $uid_strings[] = $uids;
        }
        foreach ($uid_strings as $uid_string) {
            if ($uid_string) {
                if (!$this->is_clean($uid_string, 'uid_list')) {
                    return false;
                }
            }
            switch ($action) {
                case 'READ':
                    $command = "UID STORE $uid_string +FLAGS (\Seen)\r\n";
                    break;
                case 'FLAG':
                    $command = "UID STORE $uid_string +FLAGS (\Flagged)\r\n";
                    break;
                case 'UNFLAG':
                    $command = "UID STORE $uid_string -FLAGS (\Flagged)\r\n";
                    break;
                case 'ANSWERED':
                    $command = "UID STORE $uid_string +FLAGS (\Answered)\r\n";
                    break;
                case 'UNREAD':
                    $command = "UID STORE $uid_string -FLAGS (\Seen)\r\n";
                    break;
                case 'DELETE':
                    $command = "UID STORE $uid_string +FLAGS (\Deleted)\r\n";
                    break;
                case 'UNDELETE':
                    $command = "UID STORE $uid_string -FLAGS (\Deleted)\r\n";
                    break;
                case 'EXPUNGE':
                    $command = "EXPUNGE\r\n";
                    break;
                case 'COPY':
                    if (!$this->is_clean($mailbox, 'mailbox')) {
                        return false;
                    }
                    $command = "UID COPY $uid_string \"".$this->utf7_encode($mailbox)."\"\r\n";
                    break;
            }
            $this->send_command($command);
            $res = $this->get_response();
            $status = $this->check_response($res);
            if (!$status) {
                return $status;
            }
        }
        return $status;
    }

    /**
     * returns current IMAP state
     *
     * @return string one of:
     *                unconnected   = no IMAP server TCP connection
     *                connected     = an IMAP server TCP connection exists
     *                authenticated = successfully authenticated to the IMAP server
     *                selected      = a mailbox has been selected
     */
    public function get_state() {
        return $this->state;
    }

}
?>
