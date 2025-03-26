
import { useState } from "react";
import { 
  Card, 
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle 
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { 
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue
} from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { toast } from "sonner";
import { 
  Save, 
  RefreshCw, 
  Check, 
  Timer,
  ShieldCheck,
  Lock,
  ListFilter
} from "lucide-react";

const SettingsForm = () => {
  const [isSaving, setIsSaving] = useState(false);
  const [isScrapingNow, setIsScrapingNow] = useState(false);
  const [formState, setFormState] = useState({
    enabled: true,
    frequency: "daily",
    time: "03:00",
    regions: ["Toronto", "Ottawa", "Hamilton"],
    maxAge: "7",
    autoPublish: true,
    notifyAdmin: true,
    filterKeywords: "",
    apiKey: "••••••••••••••••"
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    
    // Simulate API call
    await new Promise(resolve => setTimeout(resolve, 1500));
    
    setIsSaving(false);
    toast.success("Settings saved successfully", {
      description: "Your obituary scraper settings have been updated.",
      action: {
        label: "Dismiss",
        onClick: () => {}
      }
    });
  };

  const handleManualScrape = async () => {
    setIsScrapingNow(true);
    
    // Simulate API call
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    setIsScrapingNow(false);
    toast.success("Scraping completed", {
      description: "Found 12 new obituaries from Ontario.",
      action: {
        label: "View",
        onClick: () => {}
      }
    });
  };

  const handleChange = (
    field: string, 
    value: string | boolean | string[]
  ) => {
    setFormState(prev => ({
      ...prev,
      [field]: value
    }));
  };

  return (
    <form onSubmit={handleSubmit}>
      <div className="grid gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-xl font-semibold">General Settings</CardTitle>
            <CardDescription>
              Configure how the obituary scraper works on your site
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="enabled" className="text-base">Enable Scraper</Label>
                <p className="text-sm text-muted-foreground">
                  Automatically collect obituaries from Ontario
                </p>
              </div>
              <Switch 
                id="enabled"
                checked={formState.enabled}
                onCheckedChange={(checked) => handleChange("enabled", checked)}
              />
            </div>
            
            <Separator />
            
            <div className="grid gap-6 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="frequency">Scrape Frequency</Label>
                <Select 
                  value={formState.frequency}
                  onValueChange={(value) => handleChange("frequency", value)}
                >
                  <SelectTrigger id="frequency" className="w-full">
                    <SelectValue placeholder="Select frequency" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="hourly">Hourly</SelectItem>
                    <SelectItem value="daily">Daily</SelectItem>
                    <SelectItem value="weekly">Weekly</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  How often to check for new obituaries
                </p>
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="time">Scrape Time</Label>
                <Input
                  id="time"
                  type="time"
                  value={formState.time}
                  onChange={(e) => handleChange("time", e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  When to run the scraper (24h format)
                </p>
              </div>
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="regions">Ontario Regions</Label>
              <div className="flex flex-wrap gap-2">
                {["Toronto", "Ottawa", "Hamilton", "London", "Windsor", "Kitchener", "Sudbury", "Thunder Bay"].map(region => (
                  <Button
                    key={region}
                    type="button"
                    variant={formState.regions.includes(region) ? "secondary" : "outline"}
                    size="sm"
                    onClick={() => {
                      const newRegions = formState.regions.includes(region)
                        ? formState.regions.filter(r => r !== region)
                        : [...formState.regions, region];
                      handleChange("regions", newRegions);
                    }}
                    className="text-xs"
                  >
                    {region}
                    {formState.regions.includes(region) && (
                      <Check className="ml-1 h-3 w-3" />
                    )}
                  </Button>
                ))}
              </div>
              <p className="text-xs text-muted-foreground">
                Select the regions to collect obituaries from
              </p>
            </div>
            
            <div className="grid gap-6 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="maxAge">Retention Period (days)</Label>
                <Input
                  id="maxAge"
                  type="number"
                  min="1"
                  max="365"
                  value={formState.maxAge}
                  onChange={(e) => handleChange("maxAge", e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  How many days to keep obituaries before archiving
                </p>
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="filterKeywords">Filtering Keywords</Label>
                <Input
                  id="filterKeywords"
                  placeholder="Enter comma-separated terms"
                  value={formState.filterKeywords}
                  onChange={(e) => handleChange("filterKeywords", e.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                  Optional: Filter obituaries containing these terms
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle className="text-xl font-semibold">Display Settings</CardTitle>
            <CardDescription>
              Control how obituaries are shown on your website
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="autoPublish" className="text-base">Auto-Publish</Label>
                <p className="text-sm text-muted-foreground">
                  Automatically publish new obituaries without review
                </p>
              </div>
              <Switch 
                id="autoPublish"
                checked={formState.autoPublish}
                onCheckedChange={(checked) => handleChange("autoPublish", checked)}
              />
            </div>
            
            <Separator />
            
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label htmlFor="notifyAdmin" className="text-base">Email Notifications</Label>
                <p className="text-sm text-muted-foreground">
                  Send email when new obituaries are found
                </p>
              </div>
              <Switch 
                id="notifyAdmin"
                checked={formState.notifyAdmin}
                onCheckedChange={(checked) => handleChange("notifyAdmin", checked)}
              />
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle className="text-xl font-semibold">
              <div className="flex items-center">
                <ShieldCheck className="h-5 w-5 mr-2 text-muted-foreground" />
                API Configuration
              </div>
            </CardTitle>
            <CardDescription>
              Secure access to obituary data sources
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="apiKey">API Key</Label>
                  <Button 
                    type="button" 
                    variant="ghost" 
                    size="sm"
                    className="h-8 text-xs text-muted-foreground hover:text-foreground"
                  >
                    <Lock className="h-3 w-3 mr-1.5" />
                    Generate New
                  </Button>
                </div>
                <div className="relative">
                  <Input
                    id="apiKey"
                    type="password"
                    value={formState.apiKey}
                    onChange={(e) => handleChange("apiKey", e.target.value)}
                    className="pr-24"
                  />
                  <Button 
                    type="button" 
                    variant="ghost" 
                    size="sm"
                    className="absolute right-1 top-1 h-7 text-xs text-muted-foreground hover:text-foreground"
                    onClick={() => {
                      handleChange("apiKey", "YOUR_API_KEY_HERE");
                      toast.info("API key revealed", {
                        description: "Remember to keep this key secure."
                      });
                    }}
                  >
                    Show Key
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Used to authenticate with obituary data providers
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle className="text-xl font-semibold">Manual Controls</CardTitle>
            <CardDescription>
              Manually run operations and view status
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div className="space-y-1">
                <h4 className="font-medium">Run Scraper Now</h4>
                <p className="text-sm text-muted-foreground">
                  Manually trigger the obituary scraper
                </p>
              </div>
              <Button 
                type="button" 
                variant="secondary" 
                onClick={handleManualScrape}
                disabled={isScrapingNow}
              >
                {isScrapingNow ? (
                  <>
                    <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                    Scraping...
                  </>
                ) : (
                  <>
                    <RefreshCw className="h-4 w-4 mr-2" />
                    Scrape Now
                  </>
                )}
              </Button>
            </div>
            
            <Separator className="my-6" />
            
            <div className="space-y-4">
              <h4 className="font-medium">Status Information</h4>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="flex items-center p-3 bg-secondary/50 rounded-md">
                  <Timer className="h-5 w-5 text-muted-foreground mr-3" />
                  <div>
                    <p className="text-sm font-medium">Next Scheduled Run</p>
                    <p className="text-xs text-muted-foreground">Today at 3:00 AM</p>
                  </div>
                </div>
                
                <div className="flex items-center p-3 bg-secondary/50 rounded-md">
                  <ListFilter className="h-5 w-5 text-muted-foreground mr-3" />
                  <div>
                    <p className="text-sm font-medium">Last Run Results</p>
                    <p className="text-xs text-muted-foreground">12 new obituaries found</p>
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
          <CardFooter className="flex justify-end space-x-2 border-t p-6">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                // Reset form
                toast.info("Changes discarded");
              }}
            >
              Cancel
            </Button>
            <Button 
              type="submit"
              disabled={isSaving}
            >
              {isSaving ? (
                <>
                  <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                  Saving...
                </>
              ) : (
                <>
                  <Save className="h-4 w-4 mr-2" />
                  Save Settings
                </>
              )}
            </Button>
          </CardFooter>
        </Card>
      </div>
    </form>
  );
};

export default SettingsForm;
