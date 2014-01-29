<?php

require_once "../functions.php";

try {
//Load the images 
$output = mergeImages(
    array(
        '../images/composite1.jpg', 
        '../images/composite2.jpg',
        '../images/composite3.jpg',
    ),
    200,
    50, 1
);

//Output the final image
$output->setImageFormat('png');

header("Content-Type: image/png");
echo $output->getImageBlob();

exit(0);

}
catch (\Exception $e) {
    echo "Exception caught: ".$e->getMessage();
}

function generateBlendImage($height, $overlap, $contrast = 10, $midpoint = 0.5) {
    $imagick = new Imagick();
    $imagick->newPseudoImage($height, $overlap, 'gradient:black-white');
    $quanta = $imagick->getQuantumRange();
    $imagick->sigmoidalContrastImage(true, $contrast, $midpoint * $quanta["quantumRangeLong"]);
    $imagick->rotateImage('black', -90);
    return $imagick; 
}


function mergeImages(array $srcImages, $outputHeight, $overlap, $contrast = 10, $midpoint = 0.5) {

    $images = array();
    $newImageWidth = 0;
    $newImageHeight = 0;

    foreach ($srcImages as $srcImage) {
        $nextImage = new Imagick(realpath($srcImage));
        $nextImage->resizeImage(0, $outputHeight, Imagick::FILTER_LANCZOS, 0.5);
        
        $newImageWidth += $nextImage->getImageWidth();
        $images[] = $nextImage;
        $newImageHeight = $nextImage->getImageHeight();
    }

    $newImageWidth -= $overlap * (count($srcImages) - 1);

    if ($newImageHeight == 0) {
        throw new \Exception("Failed to read source images");
    }
    
    $fadeLeftSide = generateBlendImage($newImageHeight, $overlap, $contrast, $midpoint);
    //Fade out the left part - need to negate the mask to
    //make math correct
    $fadeRightSide = clone $fadeLeftSide;
    $fadeRightSide->negateimage(false);

    //Create a new canvas to render everything in to.
    $canvas = new Imagick();
    $canvas->newImage($newImageWidth, $newImageHeight, new ImagickPixel('black'));

    $count = 0;
    $imagePosition = 0;
    /** @var $image \Imagick */
    foreach ($images as $image) {

        $finalBlending = new Imagick();
        $finalBlending->newImage(
            $image->getImageWidth(),
            $image->getImageHeight(),
            'white'
        );

        if ($count != 0) {
            $finalBlending->compositeImage(
                $fadeLeftSide,
                Imagick::COMPOSITE_ATOP,
                0, 0
            );
        }

        $offset = $image->getImageWidth() - $overlap;

        if ($count != count($images) - 1) {
            $finalBlending->compositeImage(
                $fadeRightSide,
                Imagick::COMPOSITE_ATOP,
                $offset, 0
            );
        }
        
        $image->compositeImage(
            $finalBlending,
            Imagick::COMPOSITE_COPYOPACITY,
            0, 0
        );

        $canvas->compositeimage(
            $image,
            Imagick::COMPOSITE_BLEND,
            $imagePosition, 0
        );

        $imagePosition = $imagePosition + $image->getImageWidth() - $overlap;
        $count++;
    }

    return $canvas;
}

