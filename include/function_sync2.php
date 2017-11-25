<?php
/***********************************************
* File      :   function_sync2.php
* Project   :   piwigo-videojs
* Descr     :   Handle the syncronisation process
*
* Created   :   9.07.2013
*
* Copyright 2012-2016 <xbgmsharp@gmail.com>
*
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
************************************************/

// Check whether we are indeed included by Piwigo.
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

//setlocale(LC_ALL, "en_US.UTF-8");
include_once("function_frame.php");

/***************
 *
 * Start the sync work
 *
 */

// Init value for result table
$videos = 0;
$metadata = 0;
$posters = 0;
$thumbs = 0;
!isset($errors) and $errors = array();
!isset($warnings) and $warnings = array();
!isset($infos) and $infos = array();

// Do the Check dependencies, MediaInfo & FFMPEG, share with batch manager & photo edit & admin sync
include("function_dependencies.php");
//print_r($sync_options);
if (!$sync_options['metadata'] and !$sync_options['poster'] and !$sync_options['thumb'])
{
    $errors[] = "You ask me to do nothing, are you sure?";
}

// Do the job
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result))
{
    //print_r($row);
    $filename = $row['path'];
    if (is_file($filename))
    {
        $videos++;
        //echo $filename;
        $sync_arr = array();
        $sync_arr['file'] = $filename;
	if (!is_readable($filename)) { /* Ensure file is readabale for MediaInfo and FFMEPG */
		$errors[] = "Unable to read file for syncronisation process".$filename;
		$errors[] = "File ".$filename." has wrong permission";
		continue;
	}

	/* Metadata via MediaInfo */
        $exif = array();
        if ($sync_options['metadata'])
        {
            include("mediainfo.php");
        }
        //print_r($exif);
        if (isset($exif) and $sync_options['metadata'])
        {
			$exif = array_filter($exif);
			if (isset($exif['error']))
			{
				$errors[] = $exif['error'];
			}
			else if (!empty($exif) and count($exif) > 0)
			{
				$metadata++;
				isset($sync_options['batch_manager']) and $infos[] = $filename. ' metadata: '.vjs_pprint_r($exif);
				//$infos[] = $filename. ' metadata: '.count($exif);
				$sync_arr['metadata'] = count($exif)." ".vjs_pprint_r($exif);
				if ($sync_options['metadata'] and !$sync_options['simulate'])
				{
					$dbfields = explode(",", "filesize,width,height,latitude,longitude,date_creation,rotation");
					$query = "UPDATE ".IMAGES_TABLE." SET ".vjs_dbSet($dbfields, $exif).", `date_metadata_update`=CURDATE() WHERE `id`=".$row['id'].";";
					//print $query;
					pwg_query($query);

					/* At some point we use our own table */
					//$json = json_encode($xml); /* convert XML to JSON */
					//$all_array = json_decode($json,TRUE); /*  Merge all general/video/audio in one array to remove duplicate entry */
					//$merge_arr = array_merge($all_array['File']['track'][2], $all_array['File']['track'][1], $all_array['File']['track'][0]);
					//$query = "UPDATE '".$prefixeTable."image_videojs' SET metadata=".serialize($merge_arr).", `date_metadata_update`=CURDATE() WHERE `id`=".$row['id'].";";
					//pwg_query($query);
				}
			}
        }

        /* Create poster */
        if ($sync_options['poster'])
        {
            $posters++;
            /* Init value */
            $file_wo_ext = pathinfo($row['path']);
            //$file_wo_ext['filename'] ? '' : $file_wo_ext['filename'] = substr($filename,0 ,-5);
            if (!isset($file_wo_ext['filename']) or (isset($file_wo_ext['filename']) and strlen($file_wo_ext['filename']) == 0))
            {
            	$errors[] = "Unable to read filename for generating poster ".$row['path'];
            	continue;
            }
            $output_dir = dirname($row['path']) . '/pwg_representative/';
            $in = $filename;
            $out = $output_dir.$file_wo_ext['filename'].'.'.$sync_options['output'];
            /* Report it */
            isset($sync_options['batch_manager']) ? $infos[] = $filename. ' poster: '.$out : '';
            $sync_arr['poster'] = $out;

            if (!is_dir($output_dir))
            {
                mkdir($output_dir);
            }
            if (!is_writable($output_dir))
            {
                $errors[] = "Directory ".$output_dir." has wrong permission";
            }
            else if ($sync_options['postersec'] and !$sync_options['simulate'])
            {/* We really want to create the poster */

		/* Delete any previous poster, avoid duplication on different output format */
		$extensions = array('.jpg', '.png');
		foreach ($extensions as $extension)
		{
			$ifile = $output_dir.$file_wo_ext['filename'].$extension;
			if(is_file($ifile))
			{
				unlink($ifile);
			}
		}
		/* if video is shorter fallback to last second */
                if (isset($exif['playtime_seconds']) and $sync_options['postersec'] > $exif['playtime_seconds'])
		{
                    $warnings[] = "Movie ". $filename ." is shorter than ". $sync_options['postersec'] ." secondes, fallback to ". $exif['playtime_seconds'] ." secondes";
                    $sync_options['postersec'] = (int)$exif['playtime_seconds'];
                }
		else if (!isset($exif['playtime_seconds']))
		{ /* if video is shorter than one second use the start */
                    $warnings[] = "Movie ". $filename ." is shorter than 1 second fallback to start of video";
                    $sync_options['postersec'] = "0";
                }
		/* default output to JPG */
                $ffmpeg = $sync_options['ffmpeg'] ." -ss ".$sync_options['postersec']." -i \"".$in."\" -vcodec mjpeg -vframes 1 -an -f rawvideo -y \"".$out. "\"";
                if ($sync_options['output'] == "png")
                {
                    $ffmpeg = $sync_options['ffmpeg'] ." -ss ".$sync_options['postersec']." -i \"".$in."\" -vcodec png -vframes 1 -an -f rawvideo -y \"".$out. "\"";
                }
                //echo $ffmpeg;
                $log = system($ffmpeg, $retval);
                //$infos[] = $filename. ' poster : retval:'. $retval. ", log:". print_r($log, True);
                if($retval != 0 or !file_exists($out))
                {
                    $errors[] = "Error poster running ffmpeg/avconv, try it manually, check your webserver error log:\n<br/>". $ffmpeg;
                }
				else
				{/* We have a poster, lets update the DB */

					/* Update DB */
					$query = "UPDATE ".IMAGES_TABLE." SET `representative_ext`='".$sync_options['output']."' WHERE `id`=".$row['id'].";";
					pwg_query($query);

					/* Delete any previous square or thumbnail or small images, avoid duplication on different output format */
					/* They are now out of date, thumbnail are autogenerate by Piwigo on request */
					$idata = "_data/i/".dirname($row['path']).'/pwg_representative/';
					$extensions = array('-th.jpg', '-sq.jpg', '-th.png', '-sq.png', '-sm.png', '-sm.png');
					foreach ($extensions as $extension)
					{
						$ifile = $idata.$file_wo_ext['filename'].$extension;
						if(is_file($ifile))
						{
							unlink($ifile);
						}
					}

					/* Generate the overlay */
					if($sync_options['posteroverlay'])
					{
						add_movie_frame($out);
						isset($sync_options['batch_manager']) ? $infos[] = $filename. ' overlay: '.$out : '';
						$sync_arr['overlay'] = "add movie frame on ".$out;
					}
				}
            }
        } /* End poster */

        /* Create multiple thumbnails */
        if ($sync_options['thumb'])
        {
            if (isset($exif['playtime_seconds']) and isset($sync_options['thumbsec']) and isset($sync_options['thumbsize']))
            {
                /* Init value */
                $file_wo_ext = pathinfo($filename);
		if (!isset($file_wo_ext['filename']) and strlen($file_wo_ext['filename']) == 0)
		{
			$errors[] = "Unable to read filename for generating thumbnails ".$filename;
			continue;
		}
                $output_dir = dirname($row['path']) . '/pwg_representative/';

                if (!is_dir($output_dir) or !is_writable($output_dir))
                {
                    $errors[] = "Directory ".$output_dir." doesn't exist or wrong permission";
                }
                else if ($sync_options['thumbsec'] and !$sync_options['simulate'])
                {/* We really want to create the thumbnails */

			/* Delete any previous thumbnails, avoid duplication on different output format */
			$filematch = $output_dir.$file_wo_ext['filename']."-th_*";
			$matches = glob($filematch);
			if ( is_array ( $matches ) ) {
			foreach ( $matches as $eachfile) {
				if(is_file($eachfile))
				{
					unlink($eachfile);
				}
			}
		}

		/* Override the thumbsize (default 120x68) in order to respect the video aspect ratio base on the user specify width */
		/* https://github.com/xbgmsharp/piwigo-videojs/issues/52 */
		$thumb_witdh = preg_split("/x/", $sync_options['thumbsize']);
		if (!isset($thumb_witdh[0]))
		{ /* If invalid width x height format fallback to default thumbsize (default 120x68) */
			$warnings[] = "Invalid thumbnail size [". $sync_options['thumbsize'] ."], fallback to default width 120";
			$thumb_witdh[0] = "120";
		}
		/* Takes output width (ow), divides it by aspect ratio (a), truncates digits after decimal point */
		$scale = "scale='".$thumb_witdh[0].":trunc(ow/a)'";

		/* The loop */
                $in = $filename;
                for ($second=0; $second <= $exif['playtime_seconds']; $second+=$sync_options['thumbsec'])
                {
			$thumbs++;
			$out = $output_dir.$file_wo_ext['filename']."-th_".$second.'.'.$sync_options['output'];
			/* Report it */
			isset($sync_options['batch_manager']) ? $infos[] = $filename. ' thumbnail: '.$second.' seconds '.$out : '';
			$sync_arr['thumbnail'][] = $second.' seconds '.$out;
                        /* Lets do it , default output to JPG */
                        $ffmpeg = $sync_options['ffmpeg'] ." -ss ".$second." -i \"".$in."\" -vcodec mjpeg -vframes 1 -an -f rawvideo -vf ".$scale." -y \"".$out. "\"";
                        if ($sync_options['output'] == "png")
                        {
                            $ffmpeg = $sync_options['ffmpeg'] ." -ss ".$second." -i \"".$in."\" -vcodec png -vframes 1 -an -f rawvideo -vf ".$scale." -y \"".$out. "\"";
                        }
                        $log = system($ffmpeg, $retval);
                        //$infos[] = $filename. ' thumbnail : retval:'. $retval. ", log:". print_r($log, True);
                        if($retval != 0 or !file_exists($out))
                        {
                            $errors[] = "Error thumbnail running ffmpeg/avconv, try it manually, check your webserver error log:\n<br/>". $ffmpeg;
                        }
                    }
                }
            }
        } /* End thumbnails */
        if (!isset($sync_options['batch_manager']))
        {
            $infos[] = $sync_arr;
        }
    }
}

/***************
 *
 * End the sync work
 *
 */
