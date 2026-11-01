@props(['paper' => 'a4'])
<style>
    @media print {
        @if ($paper === 'thermal')
            @page { size: 80mm 297mm; margin: 0; }
            html, body { width: 80mm; background: #fff !important; }
            body { padding: 3mm; box-sizing: border-box; }
        @else
            @page { size: A4; margin: 0; }
            html, body { background: #fff !important; }
            body { padding: 14mm; }
        @endif
    }
</style>
