<!DOCTYPE html>
<html>
<head>
    <title>Guide til Google Sheets Opsætning - Elpriser</title>
    <style>
        body { font-family: sans-serif; padding: 40px; background: #f4f7f6; line-height: 1.6; }
        .guide-container { max-width: 800px; margin: auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #eee; padding: 12px; text-align: left; }
        th { background: #f9f9f9; }
        .highlight { background: #fffdf5; font-weight: bold; color: #2ecc71; }
        .info-box { background: #e1f5fe; padding: 15px; border-radius: 8px; color: #01579b; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="guide-container">
        <h2>⚡ Vejledning til Elpris Google Sheets</h2>
        <p>For at systemet kan importere dine elpriser korrekt, skal dit Google Sheet være sat op med følgende kolonner:</p>
        
        <table>
            <thead>
                <tr><th>Kolonne</th><th>Indhold</th><th>Eksempel</th></tr>
            </thead>
            <tbody>
                <tr><td>B (Index 1)</td><td class="highlight">Dato</td><td>19-02-2026</td></tr>
                <tr><td>W (Index 22)</td><td class="highlight">Gennemsnitspris</td><td>2,45 kr</td></tr>
                <tr><td>X (Index 23)</td><td>Maksimumspris</td><td>3,10 kr</td></tr>
                <tr><td>Y (Index 24)</td><td>Minimumspris</td><td>1,80 kr</td></tr>
                <tr><td>Z (Index 25)</td><td class="highlight">Spotpris (Rå)</td><td>0,45 kr</td></tr>
            </tbody>
        </table>

        <div class="info-box">
            <strong>Vigtigt:</strong> Sørg for at arket er "Publiceret på nettet" som en <b>CSV-fil</b>. Systemet starter med at læse data fra række 5 (Index 4), så du kan have overskrifter i de øverste rækker.
        </div>
        
        <p><small>Filen skal bruge enten semikolon (;) eller komma (,) som separator.</small></p>
    </div>
</body>
</html>