<?php
namespace App\Http\Controllers;

use App\Services\Assistant\{AssistantService,Tools};
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistant, private Tools $tools) {}
    public function status(Request $request)
    {
        $role=$this->tools->role($request->user());
        return response()->json(['configured'=>(bool)config('assistant.api_key'),'role'=>$role,'identity'=>$this->tools->identity($request->user()),'tools'=>$this->tools->definitions($request->user()),'attachments'=>['image/jpeg','image/png','image/webp','application/pdf','text/plain'],'max_attachment_bytes'=>4194304]);
    }
    private function contextRules(): array
    {
        return ['context'=>'sometimes|array','context.path'=>'nullable|string|max:250','context.label'=>'nullable|string|max:200','context.text'=>'nullable|string|max:6000','context.targets'=>'sometimes|array|max:24','context.targets.*.id'=>'required|string|max:100','context.targets.*.label'=>'required|string|max:160'];
    }
    public function chat(Request $request)
    {
        $input=$request->validate(array_merge($this->contextRules(),[
            'message'=>'required|string|max:12000','messages'=>'sometimes|array|max:16','messages.*.role'=>'required|in:user,assistant','messages.*.text'=>'required|string|max:12000',
            'attachments'=>'sometimes|array|max:2','attachments.*.name'=>'nullable|string|max:150','attachments.*.mime_type'=>'required|string|in:image/jpeg,image/png,image/webp,application/pdf,text/plain','attachments.*.data'=>'required|string|max:5592410',
        ]));
        return response()->json($this->assistant->chat($request->user(),$input));
    }
    public function tool(Request $request)
    {
        $input=$request->validate(array_merge($this->contextRules(),['name'=>'nullable|string|max:80','args'=>'sometimes|array','confirmation_token'=>'nullable|string|size:48','approved'=>'required_with:confirmation_token|boolean']));
        if (!empty($input['confirmation_token'])) return response()->json($this->tools->confirm($request->user(),$input['confirmation_token'],$input['approved']));
        abort_unless(!empty($input['name']),422,'A tool name is required.');
        return response()->json($this->tools->execute($request->user(),$input['name'],$input['args'] ?? [],$input['context'] ?? []));
    }
    public function session(Request $request)
    {
        $input=$request->validate($this->contextRules());
        return response()->json($this->assistant->session($request->user(),$input['context'] ?? []));
    }
}
