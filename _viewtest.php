$msg = ['q'=>'demo q','explanation'=>'Returns demo.','sql'=>'select 1 as a','columns'=>['a','b'],
  'rows'=>[['a'=>'1','b'=>'2'],['a'=>'3','b'=>'4']],'truncated'=>true,'error'=>null];
$err = ['q'=>'bad','explanation'=>null,'sql'=>null,'columns'=>[],'rows'=>[],'truncated'=>false,'error'=>'Table not allowed: users.'];
$html = view('livewire.ask-ai', ['messages'=>[$msg,$err],'suggestions'=>['x']])->render();
echo 'len='.strlen($html).' hasTable='.(str_contains($html,'<table')?'y':'n').' hasErr='.(str_contains($html,'Table not allowed')?'y':'n')."\n";
echo "RENDER_OK\n";
