<?php
/**
 * Login/Logout logging plugin; admin component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class admin_plugin_loglog extends DokuWiki_Admin_Plugin {

    protected $logFile = 'loglog.log'; // stored in cache directory

    protected $props = array();        // hold table propaties
    protected $term_default = 'monthly';


    /**
     * Access for managers allowed
     */
    function forAdminOnly(){ return false; }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() { return 141; }

    /**
     * handle user request
     */
    function handle() {
        global $INPUT;

        $request = $INPUT->str('time');  // $_REQUEST['time']

        // simple check url paramater 'time'
        if (strpos($request, 'W') !== false) {
            // ISO year with ISO week;              eg. 2015W04
            $term = 'weekly';
        } else if (strlen($request) == 8) {
            // Eight digit year, month and day;     eg. 20150207
            $term = 'daily';
        } else if (strlen($request) == 6) {
            // Four digit year and two digit month; eg. 201504
            $term = 'monthly'; $request .= '01';
        } else {
            $term = $this->term_default;
        }

        // set table parameters
        $time = strtotime($request) ?: time();
        switch ($term) {
            case 'daily':
                $request = strftime('%Y%m%d', $time);
                $min = strtotime('today 00:00:00', $time);
                $max = strtotime('today 23:59:59', $time);
                $newer = strftime('%Y%m%d', strtotime('+1 day', $min));
                $older = strftime('%Y%m%d', strtotime('-1 day', $min));
                $caption = strftime('%c', $time);
                break;
            case 'monthly':
                $request = strftime('%Y%m', $time);
                $min = strtotime('first day of this month 00:00:00', $time);
                $max = strtotime('last day of this month 23:59:59', $time);
                $newer = strftime('%Y%m', strtotime('+1 month', $min));
                $older = strftime('%Y%m', strtotime('-1 month', $min));
                $caption = strftime('%B %Y', $time);
                break;
            case 'weekly':
                $request = strftime('%YW%V', $time);
                $n = date('N', $time)-1; // 0:monday ... 6:sunday
                $min = strtotime("-{$n} day 00:00:00", $time);
                $max = strtotime('sunday 23:59:59', $time);
                $newer = strftime('%YW%V', strtotime('+1 week', $min));
                $older = strftime('%YW%V', strtotime('-1 week', $min));
                $caption = strftime('Week %V of %Y year', $time);
                break;
        }
        $this->props = array(
            'caption' => $caption,  // table caption
            'min'     => $min,
            'max'     => $max,
            'newer'   => $newer, // time request of newer button
            'older'   => $older, // time request of older button
        );
    }

    /**
     * output appropriate html
     */
    function html() {
        global $ID, $conf, $lang;

        // render user login/logout log table based on time request
        // with monthly, weekly or daily pagenation

        echo '<h1>'.$this->getLang('menu').'</h1>';
        echo '<div class="loglog_noprint">';
        echo $this->locale_xhtml('intro');

        echo '<p>'.$this->getLang('range').' '.
             strftime('%F (%a)',$this->props['min']).' - '.
             strftime('%F (%a)',$this->props['max']).'</p>';
        echo '</div>';

        echo '<table class="inline loglog">';
        echo '<caption>',$this->props['caption'].'</caption>';
        echo '<thead>';
        echo '<tr>';
        echo '<th>'.$this->getLang('date').'</th>';
        echo '<th>'.$this->getLang('ip').'</th>';
        echo '<th>'.$lang['user'].'</th>';
        echo '<th>'.$this->getLang('action').'</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $lines = $this->_readlines($this->props['min'],$this->props['max']);
        $lines = array_reverse($lines);

        foreach($lines as $line){
            if (empty($line)) continue; // Filter empty lines
            list($dt, $junk, $ip, $user, $msg) = explode("\t", $line, 5);
            if ($dt < $this->props['min']) continue;
            if ($dt > $this->props['max']) continue;
            if (!$user) continue;

            if ($msg == 'logged off'){
                $msg = $this->getLang('off');
                $class = 'off';
            } elseif ($msg == 'logged in permanently'){
                $msg = $this->getLang('in');
                $class = 'perm';
            } elseif ($msg == 'logged in temporarily'){
                $msg = $this->getLang('tin');
                $class = 'temp';
            } elseif ($msg == 'failed login attempt'){
                $msg = $this->getLang('fail');
                $class = 'fail';
            } elseif ($msg == 'has been automatically logged off') {
                $msg = $this->getLang('autologoff');
                $class = 'off';
            } else {
                $msg = hsc($msg);
                if (strpos($msg, 'logged off') !== false) {
                    $class = 'off';
                } elseif (strpos($msg, 'logged in permanently') !== false) {
                    $class = 'perm';
                } elseif (strpos($msg, 'logged in') !== false) {
                    $class = 'temp';
                } elseif (strpos($msg, 'failed') !== false) {
                    $class = 'fail';
                } else {
                    $class = 'unknown';
                }
            }

            echo '<tr>';
            echo '<td>'.strftime('%F %T',$dt).'</td>';
            echo '<td>'.hsc($ip).'</td>';
            echo '<td>'.hsc($user).'</td>';
            echo '<td><span class="loglog_'.$class.'">'.$msg.'</span></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<div class="pagenav loglog_noprint">';
        if ($this->props['max'] < time()){
        echo '<div class="pagenav-prev">';
        echo html_btn('newer',$ID,"p",array('do'=>'admin','page'=>'loglog','time'=>$this->props['newer']));
        echo '</div>';
        }

        echo '<div class="pagenav-next">';
        echo html_btn('older',$ID,"n",array('do'=>'admin','page'=>'loglog','time'=>$this->props['older']));
        echo '</div>';
        echo '</div>';

    }

    /**
     * Read loglines backward
     *
     * @param int $min - start time (in seconds)
     * @param int $max - end time (in seconds)
     * @return array     lines of log data
     */
    function _readlines($min,$max){
        global $conf;
        $file = $conf['cachedir'].'/'.$this->logFile;


        $data  = array();
        $lines = array();
        $chunk_size = 8192;

        if (!@file_exists($file)) return $data;
        $fp = fopen($file, 'rb');
        if ($fp===false) return $data;

        //seek to end
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $chunk = '';

        while($pos){

            // how much to read? Set pointer
            if ($pos > $chunk_size) {
                $pos -= $chunk_size;
                $read = $chunk_size;
            } else {
                $read = $pos;
                $pos  = 0;
            }
            fseek($fp,$pos);

            $tmp = fread($fp,$read);
            if ($tmp === false) break;
            $chunk = $tmp.$chunk;

            // now split the chunk
            $cparts = explode("\n",$chunk);

            // keep the first part in chunk (may be incomplete)
            if ($pos) $chunk = array_shift($cparts);

            // no more parts available, read on
            if (!count($cparts)) continue;

            // get date of first line:
            list($cdate) = explode("\t",$cparts[0]);

            if ($cdate > $max) continue; // haven't reached wanted area, yet

            // put the new lines on the stack
            $lines = array_merge($cparts,$lines);

            if ($cdate < $min) break; // we have enough
        }
        fclose($fp);

        return $lines;
    }

}
