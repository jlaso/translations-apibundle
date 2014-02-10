<?php


namespace JLaso\TranslationsApiBundle\Tools;


class ImageTools
{

    const UPLOAD_MAX_WIDTH = 1024;
    const UPLOAD_MAX_HEIGHT = 1024;

    // origin format
    const SAME_AS_ORIGIN = 0;
    // destination format
    const AS_EXTENSION   = 1;
    // action
    const MOVE_ORIG_DEST = 2;
    const COPY_ORIG_DEST = 3;
    // resize ways
    const PROPORTIONAL   = 4;
    const TO_HEIGHT      = 5;
    const TO_WIDTH       = 6;
    //const CROP           = 7;
    const EXACT_SIZE     = 8;

    public static function resizeImage($origin,$w,$h,$_options=array()){

        $options = array_merge(array(
                'resize'        => self::PROPORTIONAL,
                'destination'   => $origin,
                'action'        => self::MOVE_ORIG_DEST, // or COPY_ORIG_DEST
                //'inputFormat'   => self::AS_EXTENSION,
                'outputFormat'  => self::SAME_AS_ORIGIN,
            ),$_options);

        if (!file_exists($origin)) return false;

        // read file
        $image_info = getimagesize($origin);
        $image_type = $image_info[2];
        if($image_info[0]>self::UPLOAD_MAX_WIDTH || $image_info[1]>self::UPLOAD_MAX_HEIGHT)
            return "Exceeded maximum dimensions (".self::UPLOAD_MAX_WIDTH."x".self::UPLOAD_MAX_HEIGHT.")";
        if( $image_type == IMAGETYPE_JPEG ) {
            $image  = imagecreatefromjpeg($origin);
            $extOrig= 'jpg';
        }elseif( $image_type == IMAGETYPE_GIF ) {
            $image  = imagecreatefromgif($origin);
            $extOrig= 'gif';
        }elseif( $image_type == IMAGETYPE_PNG ) {
            $image  = imagecreatefrompng($origin);
            $extOrig= 'png';
        }

        // destination file extension calculation
        $extDest = $options['outputFormat']===self::SAME_AS_ORIGIN ?
            $extOrig:
            ImageTools::getExtension($options['destination']);

        // redimensionar
        $wimg = imagesx($image);
        $himg = imagesy($image);

        $resizeMode = $options['resize'];
        switch ($resizeMode) {
            case self::TO_HEIGHT:
                $ratio = $h / $himg;
                $height= $h;
                $width = $wimg * $ratio;
                break;

            case self::TO_WIDTH:
                $ratio = $w / $wimg;
                $width = $w;
                $height= $himg * $ratio;
                break;

            case self::EXACT_SIZE:
                $width  = $w;
                $height = $h;
                break;

            case self::PROPORTIONAL:
            default:
                $ratioh = $h / $himg;
                $ratiow = $w / $wimg;
                $ratio  = $ratioh>$ratiow ? $ratiow : $ratioh;
                $width  = $wimg * $ratio;
                $height = $himg * $ratio;
                break;

        }

        // process
        $new_image = imagecreatetruecolor($width, $height);
        imagecopyresampled($new_image, $image, 0, 0, 0, 0,
            $width, $height, $wimg, $himg);
        imagedestroy($image);

        // grabar el archivo
        $destination = $options['destination'];
        if( $extDest == 'jpg' || $extDest == 'jpeg') {
            imagejpeg($new_image, $destination);
        }elseif( $extDest == 'gif' ) {
            imagegif( $new_image, $destination);
        }elseif( $extDest == 'png' ) {
            imagepng( $new_image, $destination);
        }
        // dar permisos al archivo recien creado
        //chmod($destination,0777);

        // borrar el origen si asi se ha especificado
        if($options['action']==self::MOVE_ORIG_DEST){
            @unlink($origin);
        }
        imagedestroy($new_image);
        return true;
    }

}