@props(['paper' => 'a4'])
<style>
    @media print {
        @page { margin: 0; }
        html, body { background: #fff !important; }
        body { padding: {{ $paper === 'thermal' ? '3mm' : '14mm' }}; }
    }
</style>
