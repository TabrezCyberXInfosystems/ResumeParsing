<?php
require 'vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;

function extractTextFromElements($elements) {
    $text = '';
    foreach ($elements as $element) {
      
        if ($element instanceof Text) {
            $text .= $element->getText() . "\n";
        } elseif ($element instanceof TextRun) {
            foreach ($element->getElements() as $subElement) {
                if ($subElement instanceof Text) {
                    $text .= $subElement->getText() . ' ';
                }
            }
            $text .= "\n";
        } elseif (method_exists($element, 'getElements')) {
            $text .= extractTextFromElements($element->getElements());
        }
    }
    return $text;


}

function cleanLine($line) {
    $line = preg_replace('/[\x00-\x1F\x7F\xA0\xAD\x{200B}-\x{200D}\x{2028}-\x{202F}\x{2060}-\x{206F}\x{FEFF}]/u', '', $line);
    $line = preg_replace('/[^\P{C}\n]+/u', '', $line);
    $line = preg_replace('/�||||●|▪|•|∙|■|□|▪️|⚫|▶|–|−|●|▪|•|●/', '', $line);
    return trim($line);
}

function isValidLine($line) {
    $line = cleanLine($line);
    if (stripos($line, 'blueprint') !== false) return false;
    $clean = preg_replace('/[^\x20-\x7E]/', '', $line);
    $ratio = strlen($clean) / (strlen($line) ?: 1);
    return $ratio > 0.5 && strlen($line) > 1;
}

function fixMissingSpaces($text) {
  $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text);
  $text = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $text);
  $text = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $text);
  $text = preg_replace('/(\d+)\s*\n\s*(st|nd|rd|th)/i', '$1$2', $text);
  
    return $text;
}

function splitSectionsSmartly($lines) {

  
  $sections = [];
  $current_section = 'Name';
  $collectingAddress = false;
  $collectingAchievements = false;
  
    $aliases = [
      'Technical Skills' => 'Skills',
      'Skills' => 'Skills',
      'Projects' => 'Projects',
      'Project' => 'Projects',
      'Responsibilities' => 'Responsibilities',
      'Summary' => 'Summary',
      'Professional Summary' => 'Summary',
      'Objective' => 'Summary',
      'About Me' => 'Summary',
      'Education' => 'Education',
      'Achievements' => 'Achievements',
  ];
  
  $key = 0;
  foreach ($lines as $key => $line) {
   
        $line = cleanLine(mb_convert_encoding($line, 'UTF-8', 'auto'));
        if (!isValidLine($line)) continue;

        $foundContact = false;
        if (preg_match_all('/\+?\d[\d\s\-\(\)]{7,}\d/', $line, $matches)) {
          $phones = [];
          foreach ($matches[0] as $match) {
            
              $cleaned = preg_replace('/\s+/', ' ', trim($match));
      
            
              $digitCount = preg_match_all('/\d/', $cleaned);
      
              if (
                  $digitCount >= 10 && $digitCount <= 15 &&
      
                  !preg_match('/\b\d{4}[-\/]\d{4}\b/', $cleaned) &&
      
                  !preg_match('/\b\d{2}[-\/]\d{2}[-\/]\d{4}\b/', $cleaned) &&
      
                  !preg_match('/^\d{2,3}$/', $cleaned) &&
      
                  !preg_match('/\d{4}[-\/]\d{4}\s+\d{2,3}/', $cleaned)
              ) {
                  $phones[] = $cleaned;
              }
          }
      
          if (!empty($phones)) {
              $sections['Contact'][] = 'Phone: ' . implode(', ', $phones);  
              $foundContact = true;
          }
      }
        
      if (preg_match_all('/(?:✉️|📧|\b[Ee]mail\b[:\-]?\s*|\b[Mm]ail\b[:\-]?\s*)?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $line, $matches)) {
        foreach ($matches[1] as $email) {
            // Avoid duplicates if email already added
            if (!in_array('Email: ' . $email, $sections['Contact'] ?? [])) {
                $sections['Contact'][] = 'Email: ' . $email;
            }
        }
        $foundContact = true;
    }


        if ($foundContact) continue;

        if (preg_match('/^Language[s]?\s*[:\-]?\s*(.+)/i', $line, $match)) {
            $sections['Language'][] = cleanLine($match[1]);
            continue;
        }

        if (preg_match('/^Hobbies\s*[:\-]?\s*(.+)/i', $line, $match)) {
            $sections['Hobbies'][] = cleanLine($match[1]);
            continue;
        }

        if (preg_match('/^(Permanent Address|Temporary Address)\s*[:\-]?\s*(.*)/i', $line, $match)) {
            $type = $match[1];
            $content = $match[2];
            if (!empty($content)) {
                $sections[$type][] = $content;
            }
            $current_section = $type;
            
            $collectingAddress = true;
            continue;
        }

        if ($collectingAddress && preg_match('/^[A-Za-z ]+\s*[:\-]?\s*$/', $line)) {
        
            $collectingAddress = false;
        }


        
        if ($collectingAddress) {
          
            $sections[$current_section][] = $line;
            continue;
        }

        if (preg_match('/^(Father[’\'s]* Name|Mother[’\'s]* Name|Date of Birth|DOB)\s*[:\-]?\s*(.+)/i', $line, $match)) {
            $sections['Personal Info'][] = $match[1] . ': ' . cleanLine($match[2]);
            continue;
        }
        if (preg_match('/^Achievements\s*[:\-]?\s*$/i', $line)) {
         
            $sections['Achievements'][] = "Achievements:";
            $collectingAchievements = true;
            $current_section = 'Achievements';
            continue;
        }
    
      
        if ($collectingAchievements && preg_match('/^[\-\•]\s+.+/', $line)) {
            $sections['Achievements'][] = $line;
            continue;
        }

        if ($collectingAchievements && preg_match_all('/[\-\•]\s+.+/', $line, $matches)) {
            foreach ($matches[0] as $bullet) {
                $sections['Achievements'][] = $bullet;
            }
            continue;
        }
        if (preg_match('/^([A-Z][a-zA-Z ]{2,})\s*[:\-]?\s*$/', $line, $match)) {
          $possible_heading = trim($match[1]);
          
          if (strlen($possible_heading) <= 25 && substr_count($possible_heading, ' ') <= 4) {
              $current_section = $aliases[$possible_heading] ?? $possible_heading;
             
              
              if ($current_section === 'Header') $current_section = 'Name';
              continue;
          }
         
        }
        if (preg_match_all('/(Languages?|Database|Responsibilities|Projects?)\s*[:\-]/i', $line, $matches, PREG_OFFSET_CAPTURE)) {
          

            foreach ($matches[0] as $i => $match) {
                $start = $match[1];
                $end = isset($matches[0][$i + 1]) ? $matches[0][$i + 1][1] : strlen($line);
                $chunk = trim(substr($line, $start, $end - $start));
                $parts = explode(':', $chunk, 2);
                if (count($parts) === 2) {
                    $section = ucfirst(trim($parts[0]));
                    $content = cleanLine(trim($parts[1]));
                    $normalized = $aliases[$section] ?? $section;
                    if (!isValidLine($content)) continue;
                    $sections[$normalized][] = $content;
                }
            }
        } else {
          
            $sections[$current_section][] = $line;
        }
    }
   
    return $sections;
}

if (isset($_FILES['resume'])) {
    $file = $_FILES['resume']['tmp_name'];
    $filename = $_FILES['resume']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $text = '';

    try {
        if ($ext === 'pdf') {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file);
            $text = $pdf->getText();
        } elseif (in_array($ext, ['doc', 'docx'])) {
            $phpWord = IOFactory::load($file);
            foreach ($phpWord->getSections() as $section) {
                $text .= extractTextFromElements($section->getElements());
            }
        } else {
            echo "<div class='alert alert-danger text-center'>Please upload a PDF or Word (.doc/.docx) file.</div>";
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        $text = fixMissingSpaces($text);
        if (!empty($text)) {
          $lines = array_filter(array_map('trim', explode("\n", $text)));
         
            $sections = splitSectionsSmartly($lines);
        }
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Failed to parse file: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Resume Parser</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@yaireo/tagify/dist/tagify.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .card { border-radius: 12px; margin-top: 30px; }
    .form-control { margin-bottom: 10px; }
    .section-title { font-weight: bold; font-size: 1.2rem; margin-top: 20px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card p-4 shadow-lg">
      <h3 class="text-center text-primary">Upload Resume</h3>
      <form method="POST" enctype="multipart/form-data">
        <input type="file" name="resume" class="form-control mb-3" required>
        <button class="btn btn-success w-100">Upload</button>
      </form>
      
      <?php if (!empty($sections)): ?>
        <hr>
        <h4 class="text-success">Parsed Resume</h4>
        <form>
          <?php foreach ($sections as $section => $content): ?>
            <?php
              $normalized = strtolower(trim($section));
              $content = array_filter(array_map('cleanLine', $content), 'isValidLine');
              $displayTitle = ucwords($section);
            ?>
            <div class="section-title"><?= htmlspecialchars($displayTitle) ?></div>

            <?php if (in_array($normalized, ['skills'])): ?>
              <input name="skills" class="form-control tag-input" value="<?= htmlspecialchars(implode(',', $content)) ?>">

            <?php elseif ($normalized === 'language'): ?>
              <input name="language" class="form-control tag-input" value="<?= htmlspecialchars(implode(',', $content)) ?>">

            <?php elseif (in_array($normalized, ['permanent address', 'temporary address'])): ?>
              <input name="<?= $normalized ?>" class="form-control" value="<?= htmlspecialchars(implode(', ', $content)) ?>">

            <?php elseif (in_array($normalized, ['summary', 'professional summary', 'objective', 'about me'])): ?>
              <textarea class="form-control" rows="8"><?= htmlspecialchars(implode(' ', $content)) ?></textarea>

            <?php elseif (in_array($normalized, ['experience', 'work experience'])): ?>
              <textarea class="form-control" rows="8"><?= htmlspecialchars(implode("\n", $content)) ?></textarea>

              <?php elseif (in_array($normalized, ['education', 'education'])): ?>
                <textarea class="form-control" rows="8"><?= htmlspecialchars(implode("\n", $content)) ?></textarea>

                <?php elseif (in_array($normalized, ['Achievements', 'Achievements'])): ?>
               <textarea class="form-control" rows="2"><?= htmlspecialchars(implode("\n", $content)) ?></textarea>

              <?php else: ?>
    <?php foreach ($content as $line): ?>
        <?php if (str_word_count($line) > 22): ?>
        
            <textarea class="form-control mb-2" rows="5"><?= htmlspecialchars($line) ?></textarea>
        <?php else: ?>
          
            <input type="text" class="form-control mb-2" value="<?= htmlspecialchars($line) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
<?php endforeach; ?>

        </form>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>
  <script>
    document.querySelectorAll('.tag-input').forEach(input => {
      new Tagify(input);    
    });
  </script>
</body>
</html>
