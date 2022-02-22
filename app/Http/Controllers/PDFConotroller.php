<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\PDFsending;
use PDF;

class PDFConotroller extends Controller
{

    public function index()
    {
        return view('myPDF');
//        $pdf = [];
//        $pdf[] = [
//
//            'name' => 'Ahtisham',
//            'email' => 'ahtisham@amzonestep.com'
//
//        ];
//
//        Mail::to('ahtisham@amzonestep.com')->send(new PDFsending($pdf));
    }

    public function generatePDF()

    {

        $data = [

            'title' => 'Welcome to ItSolutionStuff.com',
            'date' => date('m/d/Y')

        ];


         $pdf = PDF::loadView('myPDF', $data);
         $pdf->download('itsolutionstuff.pdf');

        Mail::to('ahtisham@amzonestep.com')->send(new PDFsending($pdf));

    }

}
