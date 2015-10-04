<?php
/**
 * Login/Logout logging plugin; admin component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'admin.php');


class admin_plugin_loglog extends DokuWiki_Admin_Plugin {

    protected $logFile = 'loglog.log'; // stored in cache directory

    protected $table = array();
    protected $term;

    function __construct() {
        // http://php.net/manual/ja/datetime.formats.relative.php
        $this->term = array(
            'daily' => array(
                'caption' => '%c',
                'min'  => 'today 00:00:00',
                'max'  => 'today 23:59:59',
                'next' => '+1 day 00:00:00',
                'prev' => '-1 day 00:00:00',
            ),
            'weekly' => array(
                'caption' => 'Week %V of %Y year',
                'min'  => '-1 week monday 00:00:00', // thsi week start
                'max'  => 'sunday 23:59:59',         // this week end
                'next' => '+1 week 00:00:00',
                'prev' => '-1 week 00:00:00',
            ),
            'monthly' => array(
                'caption' => '%B %Y',
                'min'  => 'first day of this month 00:00:00',
                'max'  => 'last day of this month 23:59:59',
                'next' => 'first day of +1 month 00:00:00',
                'prev' => 'first day of -1 month 00:00:00',
            ),
        );
    }

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

        // table pagenation
        $this->table['term'] = $INPUT->str('term', 'weekly');
        $term = $this->table['term'];

        $go = $INPUT->int('time', time());
        $this->table['min'] = strtotime($this->term[$term]['min'], $go);
        $this->table['max'] = strtotime($this->term[$term]['max'], $go);
    }

    /**
     * output appropriate html
     */
    function html() {
        global $INPUT, $ID, $conf, $lang;

        // Weekly login/logout table, pagenation based on
        // ISO-8601 week number of year, weeks starting on Monday

        $term = $this->table['term'];
        $min = $this->table['min'];
        $max = $this->table['max'];

        echo '<h1>'.$this->getLang('menu').'</h1>';
        echo '<div class="loglog_noprint">';
        echo $this->locale_xhtml('intro');
        echo '</div>';

        $caption = strftime($this->term[$term]['caption'], $min);
        echo '<p>'.$this->getLang('range').' '.
             strftime('%F (%a)',$min).' - '.strftime('%F (%a)',$max).'</p>';


        echo '<table class="inline loglog">';
        echo '<caption>',$caption.'</caption>';
        echo '<tr>';
        echo '<th>'.$this->getLang('date').'</th>';
        echo '<th>'.$this->getLang('ip').'</th>';
        echo '<th>'.$lang['user'].'</th>';
        echo '<th>'.$this->getLang('action').'</th>';
        echo '</tr>';

        $lines = $this->_readlines($min,$max);
        $lines = array_reverse($lines);

        foreach($lines as $line){
            if (empty($line)) continue; // Filter empty lines
            list($dt,$junk,$ip,$user,$msg) = explode("\t",$line,5);
            if($dt < $min) continue;
            if($dt > $max) continue;
            if(!$user)     continue;

            if($msg == 'logged off'){
                $msg = $this->getLang('off');
                $class = 'off';
            }elseif($msg == 'logged in permanently'){
                $msg = $this->getLang('in');
                $class = 'perm';
            }elseif($msg == 'logged in temporarily'){
                $msg = $this->getLang('tin');
                $class = 'temp';
            }elseif($msg == 'failed login attempt'){
                $msg = $this->getLang('fail');
                $class = 'fail';
            }elseif($msg == 'has been automatically logged off') {
                $msg = $this->getLang('autologoff');
                $class = 'off';
            }else{
                $msg = hsc($msg);
                if(strpos($msg, 'logged off') !== false) {
                    $class = 'off';
                } elseif(strpos($msg, 'logged in permanently') !== false) {
                    $class = 'perm';
                } elseif(strpos($msg, 'logged in') !== false) {
                    $class = 'temp';
                } elseif(strpos($msg, 'failed') !== false) {
                    $class = 'fail';
                } else {
                    $class = 'unknown';
                }
            }

            echo '<tr>';
            //echo '<td>'.strftime($conf['dformat'],$dt).'</td>';
            echo '<td>'.strftime('%F %T',$dt).'</td>';
            echo '<td>'.hsc($ip).'</td>';
            echo '<td>'.hsc($user).'</td>';
            echo '<td><span class="loglog_'.$class.'">'.$msg.'</span></td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<div class="pagenav loglog_noprint">';
        if($max < time()){
        echo '<div class="pagenav-prev">';
        $go = strtotime($this->term[$term]['next'], $min);
        echo html_btn('newer',$ID,"p",array('do'=>'admin','page'=>'loglog','time'=>$go,'term'=>$term));
        echo '</div>';
        }

        echo '<div class="pagenav-next">';
        $go = strtotime($this->term[$term]['prev'], $min);
        echo html_btn('older',$ID,"n",array('do'=>'admin','page'=>'loglog','time'=>$go,'term'=>$term));
        echo '</div>';
        echo '</div>';

    }

    /**
     * Read loglines backward
     *
     * @param int $min - start time (in seconds)
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
            if($pos > $chunk_size){
                $pos -= $chunk_size;
                $read = $chunk_size;
            }else{
                $read = $pos;
                $pos  = 0;
            }
            fseek($fp,$pos);

            $tmp = fread($fp,$read);
            if($tmp === false) break;
            $chunk = $tmp.$chunk;

            // now split the chunk
            $cparts = explode("\n",$chunk);

            // keep the first part in chunk (may be incomplete)
            if($pos) $chunk = array_shift($cparts);

            // no more parts available, read on
            if(!count($cparts)) continue;

            // get date of first line:
            list($cdate) = explode("\t",$cparts[0]);

            if($cdate > $max) continue; // haven't reached wanted area, yet

            // put the new lines on the stack
            $lines = array_merge($cparts,$lines);

            if($cdate < $min) break; // we have enough
        }
        fclose($fp);

        return $lines;
    }

    /**
     * convert 1,2,3,4 to 1st, 2nd, 3rd, 4th etc
     */
    function ordSuffix($n) {
        $str = "$n";
        $t = ($n > 9) ? substr($str,-2,1) : 0;
        $u = substr($str,-1);
        if ($t==1) return $str . 'th';
        else switch ($u) {
            case 1: return $str . 'st';
            case 2: return $str . 'nd';
            case 3: return $str . 'rd';
            default: return $str . 'th';
        }
    }

}
